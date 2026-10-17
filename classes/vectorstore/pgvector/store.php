<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * pgvector backend.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore\pgvector;

use search_semantic\vectorstore\chunk;
use search_semantic\vectorstore\collection;
use search_semantic\vectorstore\distance;
use search_semantic\vectorstore\filter;
use search_semantic\vectorstore\hit;
use search_semantic\vectorstore\query;
use search_semantic\vectorstore\store_exception;

/**
 * PostgreSQL pgvector implementation of {@see \search_semantic\vectorstore\store}.
 *
 * Each collection is a table. Connection settings come from the semantic search admin page.
 * Queries go through the PHP pgsql extension, which Moodle already uses for PostgreSQL.
 */
class store implements \search_semantic\vectorstore\store {

    /** @var string Registry of collection dimension and distance. */
    private const REGISTRY = 'search_semantic_pgcollection';

    /** @var string[] Filterable fields stored as integers. areaid and docid are text. */
    private const INT_FIELDS = [
        'itemid', 'contextid', 'courseid', 'owneruserid', 'userid', 'groupid', 'type', 'modified',
    ];

    /** @var \PgSql\Connection|null */
    private ?\PgSql\Connection $db = null;

    /** @var bool|null Whether this server can pre-filter inside an HNSW scan. */
    private ?bool $iterativescan = null;

    /** @var string */
    private string $host;

    /** @var int */
    private int $port;

    /** @var string */
    private string $dbname;

    /** @var string */
    private string $user;

    /** @var string */
    private string $password;

    /**
     * Constructor.
     *
     * @param \stdClass|null $config Plugin config. Null reads search_semantic config.
     */
    public function __construct(?\stdClass $config) {
        $this->host = (string)($config->pgvectorhost ?? '');
        $this->port = (int)($config->pgvectorport ?? 5432);
        if ($this->port < 1) {
            $this->port = 5432;
        }
        $this->dbname = (string)($config->pgvectordbname ?? '');
        $this->user = (string)($config->pgvectoruser ?? '');
        $this->password = (string)($config->pgvectorpassword ?? '');
    }

    /**
     * Checks the database is reachable and the vector extension is installed.
     *
     * @return true|string True if ready, otherwise a human readable error message
     */
    public function is_ready(): true|string {
        if (!function_exists('pg_connect')) {
            return 'The PHP pgsql extension is not installed';
        }
        if ($this->host === '' || $this->dbname === '' || $this->user === '') {
            return 'pgvector connection is not configured';
        }
        try {
            $version = $this->vector_extension_version();
        } catch (store_exception $e) {
            return $e->debuginfo ?: $e->getMessage();
        }
        if ($version === false) {
            return 'The vector extension is not installed in the pgvector database';
        }
        return true;
    }

    /**
     * Checks whether the collection table exists, without creating it.
     *
     * @param collection $collection
     * @return bool
     */
    public function collection_exists(collection $collection): bool {
        return $this->scalar(
            'SELECT to_regclass(CAST(? AS text)) IS NOT NULL',
            [$collection->name],
        ) === 't';
    }

    /**
     * Creates the collection table and its indexes if they do not exist yet.
     *
     * @param collection $collection
     */
    public function ensure_collection(collection $collection): void {
        if ($this->vector_extension_version() === false) {
            throw new store_exception('The vector extension is not installed in the pgvector database');
        }
        $this->ensure_registry();

        if ($this->collection_exists($collection)) {
            $this->assert_same_collection($collection);
            return;
        }

        $table = $this->quote_ident($collection->name);
        $dims = (int)$collection->dimensions;
        $ops = match ($collection->distance) {
            distance::cosine => 'vector_cosine_ops',
            distance::dot => 'vector_ip_ops',
            distance::euclidean => 'vector_l2_ops',
        };
        $this->begin();
        try {
            $this->execute(
                "CREATE TABLE {$table} (
                    docid varchar(255) NOT NULL,
                    chunkindex integer NOT NULL,
                    chunktext text NOT NULL,
                    embedding vector({$dims}) NOT NULL,
                    areaid varchar(255),
                    itemid bigint,
                    contextid bigint,
                    courseid bigint,
                    owneruserid bigint,
                    userid bigint,
                    groupid bigint,
                    type integer,
                    modified bigint,
                    PRIMARY KEY (docid, chunkindex)
                )"
            );
            $hnsw = $this->quote_ident($this->index_name($collection->name, 'hnsw'));
            $this->execute("CREATE INDEX {$hnsw} ON {$table} USING hnsw (embedding {$ops})");
            foreach (['areaid', 'contextid', 'courseid'] as $field) {
                $index = $this->quote_ident($this->index_name($collection->name, $field));
                $column = $this->quote_field($field);
                $this->execute("CREATE INDEX {$index} ON {$table} ({$column})");
            }
            $this->execute(
                'INSERT INTO ' . $this->quote_ident(self::REGISTRY) . ' (name, dimensions, distance) VALUES (?, ?, ?)',
                [$collection->name, $dims, $collection->distance->value],
            );
            $this->commit();
        } catch (store_exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Removes the collection table and its registry row.
     *
     * @param collection $collection
     */
    public function drop_collection(collection $collection): void {
        $this->begin();
        try {
            $this->execute('DROP TABLE IF EXISTS ' . $this->quote_ident($collection->name));
            if ($this->registry_exists()) {
                $this->execute(
                    'DELETE FROM ' . $this->quote_ident(self::REGISTRY) . ' WHERE name = ?',
                    [$collection->name],
                );
            }
            $this->commit();
        } catch (store_exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Writes chunks, replacing every stored chunk of each docid in the batch.
     *
     * @param collection $collection
     * @param chunk[] $chunks
     */
    public function upsert(collection $collection, array $chunks): void {
        if (!$chunks) {
            return;
        }
        foreach ($chunks as $chunk) {
            if (count($chunk->vector) !== $collection->dimensions) {
                throw new store_exception('Chunk vector length does not match the collection');
            }
        }

        $table = $this->quote_ident($collection->name);
        $this->begin();
        try {
            $docids = array_values(array_unique(array_map(fn(chunk $chunk): string => $chunk->docid, $chunks)));
            $in = implode(', ', array_fill(0, count($docids), '?'));
            $this->execute("DELETE FROM {$table} WHERE docid IN ({$in})", $docids);

            $metafields = array_values(array_filter(
                chunk::FILTERABLE_FIELDS,
                fn(string $field): bool => $field !== 'docid',
            ));
            $columns = ['docid', 'chunkindex', 'chunktext', 'embedding', ...$metafields];
            $quoted = array_map(fn(string $field): string => $field === 'embedding' ? '"embedding"' : $this->quote_field_or_column($field), $columns);
            $holders = array_fill(0, count($columns), '?');
            $holders[3] = 'CAST(? AS vector)';
            $insert = 'INSERT INTO ' . $table . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $holders) . ')';
            foreach ($chunks as $chunk) {
                $params = [
                    $chunk->docid,
                    $chunk->index,
                    $chunk->text,
                    $this->vector_literal($chunk->vector),
                ];
                foreach ($metafields as $field) {
                    $params[] = $chunk->metadata[$field] ?? null;
                }
                $this->execute($insert, $params);
            }
            $this->commit();
        } catch (store_exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Deletes chunks matching the filter.
     *
     * @param collection $collection
     * @param filter $filter
     */
    public function delete(collection $collection, filter $filter): void {
        [$where, $params] = $this->compile_filter($filter);
        $this->execute('DELETE FROM ' . $this->quote_ident($collection->name) . ' WHERE ' . $where, $params);
    }

    /**
     * Nearest-neighbour search with the filter applied inside the scan.
     *
     * @param collection $collection
     * @param query $query
     * @return hit[]
     */
    public function search(collection $collection, query $query): array {
        if (count($query->vector) !== $collection->dimensions) {
            throw new store_exception('Query vector length does not match the collection');
        }
        $vector = $this->vector_literal($query->vector);
        $operator = match ($collection->distance) {
            distance::cosine => '<=>',
            distance::dot => '<#>',
            distance::euclidean => '<->',
        };
        $score = match ($collection->distance) {
            distance::cosine => "1 - (embedding {$operator} CAST(? AS vector))",
            distance::dot, distance::euclidean => "-(embedding {$operator} CAST(? AS vector))",
        };

        $params = [$vector];
        $where = [];
        if ($query->filter !== null && $query->filter->op !== filter::OP_ALL) {
            [$filtersql, $filterparams] = $this->compile_filter($query->filter);
            $where[] = $filtersql;
            array_push($params, ...$filterparams);
        }
        if ($query->minscore !== null) {
            $where[] = "({$score}) >= ?";
            $params[] = $vector;
            $params[] = $query->minscore;
        }
        $params[] = $vector;

        $metafields = array_values(array_filter(
            chunk::FILTERABLE_FIELDS,
            fn(string $field): bool => $field !== 'docid',
        ));
        $select = ['"docid"', '"chunkindex"', '"chunktext"'];
        foreach ($metafields as $field) {
            $select[] = $this->quote_field($field);
        }
        $select[] = $score . ' AS score';
        $sql = 'SELECT ' . implode(', ', $select) .
            ' FROM ' . $this->quote_ident($collection->name);
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY embedding {$operator} CAST(? AS vector) LIMIT " . (int)$query->limit;

        $this->begin();
        try {
            if ($this->supports_iterative_scan()) {
                $this->execute('SET LOCAL hnsw.iterative_scan = relaxed_order');
            }
            $rows = $this->rows($sql, $params);
            $this->commit();
        } catch (store_exception $e) {
            $this->rollback();
            throw $e;
        }

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = new hit(
                (string)$row['docid'],
                (int)$row['chunkindex'],
                (float)$row['score'],
                (string)$row['chunktext'],
                $this->metadata_from_row($row),
            );
        }
        return $hits;
    }

    /**
     * Opens the configured PostgreSQL connection.
     *
     * @return \PgSql\Connection
     */
    private function db(): \PgSql\Connection {
        if ($this->db) {
            return $this->db;
        }
        if (!function_exists('pg_connect')) {
            throw new store_exception('The PHP pgsql extension is not installed');
        }
        if ($this->host === '' || $this->dbname === '' || $this->user === '') {
            throw new store_exception('pgvector connection is not configured');
        }
        $connection = "host='" . $this->conn_value($this->host) . "'" .
            " port='" . $this->port . "'" .
            " user='" . $this->conn_value($this->user) . "'" .
            " password='" . $this->conn_value($this->password) . "'" .
            " dbname='" . $this->conn_value($this->dbname) . "'" .
            " connect_timeout=3" .
            " options='-c client_encoding=utf8 -c standard_conforming_strings=on'";

        $conn = null;
        ob_start();
        try {
            $conn = pg_connect($connection, PGSQL_CONNECT_FORCE_NEW);
            $dberr = ob_get_contents();
        } catch (\Throwable $e) {
            $dberr = $e->getMessage();
        }
        ob_end_clean();

        $status = $conn instanceof \PgSql\Connection ? pg_connection_status($conn) : false;
        if ($status === false || $status === PGSQL_CONNECTION_BAD || !$conn instanceof \PgSql\Connection) {
            $dberr = trim((string)$dberr);
            throw new store_exception($dberr !== '' ? $dberr : 'Database connection failed');
        }
        $this->db = $conn;
        return $this->db;
    }

    /**
     * Runs a statement and discards the result.
     *
     * @param string $sql SQL with ? placeholders
     * @param array $params
     */
    private function execute(string $sql, array $params = []): void {
        pg_free_result($this->run($sql, $params));
    }

    /**
     * Runs a query and returns every row.
     *
     * @param string $sql SQL with ? placeholders
     * @param array $params
     * @return array[]
     */
    private function rows(string $sql, array $params = []): array {
        $result = $this->run($sql, $params);
        $rows = pg_fetch_all($result);
        pg_free_result($result);
        return $rows ?: [];
    }

    /**
     * Runs a query and returns the first column of the first row.
     *
     * @param string $sql SQL with ? placeholders
     * @param array $params
     * @return mixed Null when the query returns no rows
     */
    private function scalar(string $sql, array $params = []): mixed {
        $rows = $this->rows($sql, $params);
        if (!$rows) {
            return null;
        }
        return reset($rows[0]);
    }

    /**
     * Runs SQL. Placeholders are ? and are rewritten to PostgreSQL $1, $2, ...
     *
     * @param string $sql
     * @param array $params
     * @return \PgSql\Result
     */
    private function run(string $sql, array $params = []): \PgSql\Result {
        $sql = $this->placeholders($sql);
        $bound = [];
        foreach ($params as $value) {
            $bound[] = $value === null ? null : (string)$value;
        }
        try {
            $result = $bound === []
                ? @pg_query($this->db(), $sql)
                : @pg_query_params($this->db(), $sql, $bound);
        } catch (\Throwable $e) {
            throw new store_exception($e->getMessage());
        }
        if ($result === false) {
            throw new store_exception(pg_last_error($this->db()) ?: 'PostgreSQL query failed');
        }
        return $result;
    }

    /**
     * Replaces ? placeholders with $1, $2, ... in order.
     *
     * @param string $sql
     * @return string
     */
    private function placeholders(string $sql): string {
        $index = 0;
        return preg_replace_callback('/\?/', function() use (&$index): string {
            $index++;
            return '$' . $index;
        }, $sql);
    }

    /**
     * Starts a transaction.
     */
    private function begin(): void {
        $this->execute('BEGIN');
    }

    /**
     * Commits the current transaction.
     */
    private function commit(): void {
        $this->execute('COMMIT');
    }

    /**
     * Rolls back the current transaction when one is open.
     */
    private function rollback(): void {
        $status = pg_transaction_status($this->db());
        if ($status !== PGSQL_TRANSACTION_INTRANS && $status !== PGSQL_TRANSACTION_INERROR) {
            return;
        }
        $result = @pg_query($this->db(), 'ROLLBACK');
        if ($result !== false) {
            pg_free_result($result);
        }
    }

    /**
     * Escapes a connection-string value for libpq quoting.
     *
     * @param string $value
     * @return string
     */
    private function conn_value(string $value): string {
        return addcslashes($value, "'\\");
    }

    /**
     * Creates the collection registry when it is missing.
     */
    private function ensure_registry(): void {
        $table = $this->quote_ident(self::REGISTRY);
        $this->execute(
            "CREATE TABLE IF NOT EXISTS {$table} (
                name varchar(63) PRIMARY KEY,
                dimensions integer NOT NULL,
                distance varchar(16) NOT NULL
            )"
        );
    }

    /**
     * Whether the registry table exists.
     *
     * @return bool
     */
    private function registry_exists(): bool {
        return $this->scalar('SELECT to_regclass(CAST(? AS text)) IS NOT NULL', [self::REGISTRY]) === 't';
    }

    /**
     * Rejects an existing collection whose dimension or distance differs.
     *
     * @param collection $collection
     */
    private function assert_same_collection(collection $collection): void {
        $rows = $this->rows(
            'SELECT dimensions, distance FROM ' . $this->quote_ident(self::REGISTRY) . ' WHERE name = ?',
            [$collection->name],
        );
        $row = $rows[0] ?? null;
        if (!$row) {
            throw new store_exception('Collection ' . $collection->name . ' exists without distance metadata');
        }
        if ((int)$row['dimensions'] !== $collection->dimensions || $row['distance'] !== $collection->distance->value) {
            throw new store_exception(
                'Collection ' . $collection->name . ' already exists with dimensions ' .
                $row['dimensions'] . ' and distance ' . $row['distance']
            );
        }
    }

    /**
     * Installed pgvector version. False when the extension is not present.
     *
     * The store never creates the extension. Installation is the database administrator's job.
     *
     * @return string|false
     */
    private function vector_extension_version(): string|false {
        $version = $this->scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        if (!is_string($version) || $version === '') {
            return false;
        }
        return $version;
    }

    /**
     * pgvector 0.8 applies a metadata filter while scanning the HNSW index.
     *
     * @return bool
     */
    private function supports_iterative_scan(): bool {
        if ($this->iterativescan !== null) {
            return $this->iterativescan;
        }
        $version = $this->vector_extension_version();
        $this->iterativescan = is_string($version) && version_compare($version, '0.8.0', '>=');
        return $this->iterativescan;
    }

    /**
     * Compiles a filter into a parenthesised SQL expression and bound parameters.
     *
     * @param filter $filter
     * @return array{0: string, 1: array}
     */
    private function compile_filter(filter $filter): array {
        switch ($filter->op) {
            case filter::OP_ALL:
                return ['TRUE', []];
            case filter::OP_EQ:
                return [$this->quote_field($filter->field) . ' = ?', [$filter->value]];
            case filter::OP_IN:
                $values = $filter->value;
                if (!$values) {
                    return ['FALSE', []];
                }
                $holders = implode(', ', array_fill(0, count($values), '?'));
                return [$this->quote_field($filter->field) . " IN ({$holders})", array_values($values)];
            case filter::OP_RANGE:
                [$min, $max] = $filter->value;
                $parts = [];
                $params = [];
                if ($min !== null) {
                    $parts[] = $this->quote_field($filter->field) . ' >= ?';
                    $params[] = $min;
                }
                if ($max !== null) {
                    $parts[] = $this->quote_field($filter->field) . ' <= ?';
                    $params[] = $max;
                }
                return ['(' . implode(' AND ', $parts) . ')', $params];
            case filter::OP_NULL:
                return [$this->quote_field($filter->field) . ' IS NULL', []];
            case filter::OP_NOT:
                [$sql, $params] = $this->compile_filter($filter->children[0]);
                return ["(NOT {$sql})", $params];
            case filter::OP_AND:
            case filter::OP_OR:
                $glue = $filter->op === filter::OP_AND ? ' AND ' : ' OR ';
                $parts = [];
                $params = [];
                foreach ($filter->children as $child) {
                    [$sql, $childparams] = $this->compile_filter($child);
                    $parts[] = $sql;
                    array_push($params, ...$childparams);
                }
                return ['(' . implode($glue, $parts) . ')', $params];
        }
        throw new \coding_exception('Unknown filter operator: ' . $filter->op);
    }

    /**
     * Formats a vector as a pgvector literal.
     *
     * @param float[] $vector
     * @return string
     */
    private function vector_literal(array $vector): string {
        $parts = [];
        foreach ($vector as $value) {
            if (!is_numeric($value)) {
                throw new store_exception('Vector value is not numeric');
            }
            $parts[] = sprintf('%.8F', (float)$value);
        }
        return '[' . implode(',', $parts) . ']';
    }

    /**
     * Rebuilds chunk metadata from a result row.
     *
     * @param array $row
     * @return array
     */
    private function metadata_from_row(array $row): array {
        $metadata = [];
        foreach (chunk::FILTERABLE_FIELDS as $field) {
            if ($field === 'docid') {
                continue;
            }
            if ($row[$field] === null) {
                $metadata[$field] = null;
            } else if (in_array($field, self::INT_FIELDS, true)) {
                $metadata[$field] = (int)$row[$field];
            } else {
                $metadata[$field] = (string)$row[$field];
            }
        }
        return $metadata;
    }

    /**
     * Quotes a collection or index name that already passed the collection name rule.
     *
     * @param string $name
     * @return string
     */
    private function quote_ident(string $name): string {
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name)) {
            throw new \coding_exception('Invalid SQL identifier: ' . $name);
        }
        return '"' . $name . '"';
    }

    /**
     * Quotes a filterable field name.
     *
     * @param string $field
     * @return string
     */
    private function quote_field(string $field): string {
        if (!in_array($field, chunk::FILTERABLE_FIELDS, true)) {
            throw new \coding_exception('Field is not filterable: ' . $field);
        }
        return '"' . $field . '"';
    }

    /**
     * Quotes a column that is either filterable metadata or one of the chunk columns.
     *
     * @param string $field
     * @return string
     */
    private function quote_field_or_column(string $field): string {
        if (in_array($field, ['docid', 'chunkindex', 'chunktext'], true)) {
            return '"' . $field . '"';
        }
        return $this->quote_field($field);
    }

    /**
     * Short index name. A collection name can already be 63 characters.
     *
     * @param string $table
     * @param string $suffix
     * @return string
     */
    private function index_name(string $table, string $suffix): string {
        return 'i' . substr(sha1($table . ':' . $suffix), 0, 20);
    }
}
