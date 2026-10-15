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
 * pgvector store tests.
 *
 * @package    search_semantic
 * @category   test
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore\pgvector;

use search_semantic\vectorstore\chunk;
use search_semantic\vectorstore\collection;
use search_semantic\vectorstore\filter;
use search_semantic\vectorstore\query;
use search_semantic\vectorstore\store_exception;

/**
 * Tests for the pgvector store. Skipped when the configured server is not reachable.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(store::class)]
final class store_test extends \advanced_testcase {

    /** @var store|null */
    private ?store $store = null;

    /** @var string[] Collection names to drop. */
    private array $collections = [];

    /**
     * Drops collections created during the test.
     */
    protected function tearDown(): void {
        if ($this->store) {
            foreach ($this->collections as $name) {
                try {
                    $this->store->drop_collection(new collection($name, 2));
                } catch (\Throwable $e) {
                    // Cleanup must not hide the test result.
                }
            }
        }
        parent::tearDown();
    }

    /**
     * A closed port is reported as not ready and does not throw.
     */
    public function test_is_ready_reports_bad_connection(): void {
        $store = new store((object)[
            'pgvectorhost' => '127.0.0.1',
            'pgvectorport' => 1,
            'pgvectordbname' => 'moodle',
            'pgvectoruser' => 'postgres',
            'pgvectorpassword' => 'invalid',
        ]);
        $ready = $store->is_ready();
        $this->assertIsString($ready);
        $this->assertNotSame('', $ready);
    }

    /**
     * Creating the same collection twice is a no-op. A different dimension is rejected.
     */
    public function test_ensure_collection_rejects_a_different_dimension(): void {
        $store = $this->require_store();
        $name = $this->collection_name();
        $collection = new collection($name, 2);
        try {
            $store->ensure_collection($collection);
            $store->ensure_collection($collection);
            $this->assertTrue($store->collection_exists($collection));
            $rejected = false;
            try {
                $store->ensure_collection(new collection($name, 4));
            } catch (store_exception $e) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
            $this->assertTrue($store->collection_exists($collection));
        } finally {
            $store->drop_collection($collection);
        }
        $this->assertFalse($store->collection_exists($collection));
    }

    /**
     * A second upsert of a document removes chunks that are no longer present.
     */
    public function test_upsert_replaces_whole_document(): void {
        $store = $this->require_store();
        $collection = new collection($this->collection_name(), 2);
        try {
            $store->ensure_collection($collection);
            $store->upsert($collection, [
                $this->chunk('mod_forum-post-1', 0, [1, 0], 'first'),
                $this->chunk('mod_forum-post-1', 1, [1, 0], 'second'),
            ]);
            $store->upsert($collection, [
                $this->chunk('mod_forum-post-1', 0, [1, 0], 'kept'),
            ]);
            $hits = $store->search($collection, new query([1, 0], 10));
            $this->assertCount(1, $hits);
            $this->assertSame('mod_forum-post-1', $hits[0]->docid);
            $this->assertSame(0, $hits[0]->index);
            $this->assertSame('kept', $hits[0]->text);
            $this->assertEquals(7, $hits[0]->metadata['contextid']);
            $this->assertNull($hits[0]->metadata['groupid']);
        } finally {
            $store->drop_collection($collection);
        }
    }

    /**
     * The access filter runs inside the nearest-neighbour scan, before the limit.
     */
    public function test_search_applies_filter_before_limit(): void {
        $store = $this->require_store();
        $collection = new collection($this->collection_name(), 2);
        try {
            $store->ensure_collection($collection);
            $store->upsert($collection, [
                $this->chunk('mod_forum-post-1', 0, [1, 0], 'course one', ['courseid' => 1, 'contextid' => 5]),
                $this->chunk('mod_forum-post-2', 0, [0, 1], 'course two', ['courseid' => 2, 'contextid' => 9]),
            ]);

            $nearest = $store->search($collection, new query([0.2, 0.9], 1));
            $this->assertSame('mod_forum-post-2', $nearest[0]->docid);

            // Course 2 is the global nearest. Limit 1 plus a course 1 filter must still return course 1.
            $filtered = $store->search($collection, new query([0.2, 0.9], 1, filter::eq('courseid', 1)));
            $this->assertCount(1, $filtered);
            $this->assertSame('mod_forum-post-1', $filtered[0]->docid);

            $none = $store->search($collection, new query([0.2, 0.9], 5, filter::in('contextid', [])));
            $this->assertSame([], $none);

            $store->delete($collection, filter::eq('docid', 'mod_forum-post-2'));
            $remaining = $store->search($collection, new query([0.2, 0.9], 5));
            $this->assertCount(1, $remaining);
            $this->assertSame('mod_forum-post-1', $remaining[0]->docid);
        } finally {
            $store->drop_collection($collection);
        }
    }

    /**
     * Store connected to the project database, or skip when it is down.
     *
     * @return store
     */
    private function require_store(): store {
        $store = new store($this->pgconfig());
        $ready = $store->is_ready();
        if ($ready !== true) {
            $this->markTestSkipped($ready);
        }
        $this->store = $store;
        return $store;
    }

    /**
     * Connection used by Docker-backed tests. Override with PGVECTOR_* environment variables.
     *
     * @return \stdClass
     */
    private function pgconfig(): \stdClass {
        return (object)[
            'pgvectorhost' => getenv('PGVECTOR_HOST') ?: 'db',
            'pgvectorport' => getenv('PGVECTOR_PORT') ?: 5432,
            'pgvectordbname' => getenv('PGVECTOR_DBNAME') ?: 'moodle',
            'pgvectoruser' => getenv('PGVECTOR_USER') ?: 'postgres',
            'pgvectorpassword' => getenv('PGVECTOR_PASSWORD') ?: 'p@ssw0rd',
        ];
    }

    /**
     * A fresh collection name, recorded so tearDown can drop it.
     *
     * @return string
     */
    private function collection_name(): string {
        $name = 't' . substr(md5(uniqid('', true)), 0, 20);
        $this->collections[] = $name;
        return $name;
    }

    /**
     * One chunk with the metadata a search hit has to round-trip.
     *
     * @param string $docid
     * @param int $index
     * @param float[] $vector
     * @param string $text
     * @param array $extra
     * @return chunk
     */
    private function chunk(string $docid, int $index, array $vector, string $text, array $extra = []): chunk {
        return new chunk($docid, $index, $vector, $text, $extra + [
            'areaid' => 'mod_forum-post',
            'itemid' => 1,
            'contextid' => 7,
            'courseid' => 3,
            'owneruserid' => 0,
            'modified' => 1000,
        ]);
    }
}
