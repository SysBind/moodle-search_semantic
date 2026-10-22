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
 * Semantic search engine implementation.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic;

use search_semantic\local\embedding_client;
use search_semantic\vectorstore\chunk;
use search_semantic\vectorstore\collection;
use search_semantic\vectorstore\filter;
use search_semantic\vectorstore\query;
use search_semantic\vectorstore\stores;

defined('MOODLE_INTERNAL') || die();

/**
 * Semantic search engine.
 */
class engine extends \core_search\engine {
    /** @var \search_semantic\vectorstore\store|null Created lazily: core instantiates engines before they are configured. */
    private ?\search_semantic\vectorstore\store $store = null;

    /** @var embedding_client */
    private embedding_client $embedder;

    /** @var int */
    private int $querytotalcount = 0;

    public function __construct(bool $alternateconfiguration = false) {
        parent::__construct($alternateconfiguration);

        $this->embedder = new embedding_client();
    }

    /**
     * Vector store for the configured store type.
     *
     * Resolved on first use rather than in the constructor, because core constructs every search
     * engine when building admin settings (including during install) before any store is configured.
     *
     * @return vectorstore\store
     * @throws \core_search\engine_exception if no supported store type is configured.
     */
    private function get_store(): vectorstore\store {
        if ($this->store !== null) {
            return $this->store;
        }
        if (($this->config->storetype ?? '') === stores::PGVECTOR) {
            $this->store = new vectorstore\pgvector\store($this->config);
            return $this->store;
        }
        throw new \core_search\engine_exception('unknownstoretype', 'search_semantic', '', $this->config->storetype ?? '');
    }

    public function add_document($document, $fileindexing = false) {
        if (!$document instanceof \core_search\document) {
            return false;
        }
        $content = trim((string)$document->get('content'));
        if ($content === '') {
            return true;
        }

        $userid = $this->resolve_userid();
        $contextid = (int)$document->get('contextid');
        $selectedproviderid = $this->embedder->get_selected_provider_id();
        $selectedmodel = $this->embedder->get_selected_model();
        if (!$this->is_selection_compatible_with_lock($selectedproviderid, $selectedmodel)) {
            return false;
        }
        $chunks = [];
        foreach ($this->split_text($content) as $index => $textchunk) {
            $vector = $this->embedder->embed_text($textchunk, $contextid, $userid);
            if (!$vector) {
                return false;
            }
            if (!$this->is_vector_dimension_compatible_with_lock(count($vector))) {
                return false;
            }
            $chunks[] = chunk::from_document($document, $index, $vector, $textchunk);
        }
        if ($chunks) {
            $this->lock_embedding_selection($selectedproviderid, $selectedmodel, count($chunks[0]->vector));
            $collection = $this->get_collection(count($chunks[0]->vector));
            $this->get_store()->ensure_collection($collection);
            $this->get_store()->upsert($collection, $chunks);
        }
        return true;
    }

    public function delete_by_id($id) {
        $this->get_store()->delete($this->get_default_collection(), filter::eq('docid', (string)$id));
    }

    public function delete($areaid = null) {
        if ($areaid === null) {
            $this->get_store()->delete($this->get_default_collection(), filter::all());
            return;
        }
        $this->get_store()->delete($this->get_default_collection(), filter::eq('areaid', (string)$areaid));
    }

    public function execute_query($formdata, $accessinfo, $limit = 0) {
        $this->querytotalcount = 0;
        $querytext = trim((string)($formdata->q ?? ''));
        if ($querytext === '') {
            return [];
        }

        $userid = $this->resolve_userid();
        $contextid = isset($formdata->context) ? (int)$formdata->context->id : \context_system::instance()->id;
        $selectedproviderid = $this->embedder->get_selected_provider_id();
        $selectedmodel = $this->embedder->get_selected_model();
        if (!$this->is_selection_compatible_with_lock($selectedproviderid, $selectedmodel)) {
            return [];
        }
        $queryvector = $this->embedder->embed_text($querytext, $contextid, $userid);
        if (!$queryvector) {
            return [];
        }
        if (!$this->is_vector_dimension_compatible_with_lock(count($queryvector))) {
            return [];
        }

        $collection = $this->get_collection(count($queryvector));
        if (!$this->get_store()->collection_exists($collection)) {
            return [];
        }
        $limit = $limit > 0 ? $limit : \core_search\manager::MAX_RESULTS;
        $queryfilter = $this->build_query_filter($formdata, $accessinfo);
        $hits = $this->get_store()->search($collection, new query($queryvector, $limit, $queryfilter));

        $results = [];
        $seen = [];
        foreach ($hits as $hit) {
            if (isset($seen[$hit->docid])) {
                continue;
            }
            [$areaid, $itemid] = $this->split_docid($hit->docid);
            if ($areaid === '' || $itemid === 0) {
                continue;
            }
            $searcharea = $this->get_search_area($areaid);
            if (!$searcharea || $searcharea->check_access($itemid) !== \core_search\manager::ACCESS_GRANTED) {
                continue;
            }
            $docdata = [
                'id' => $hit->docid,
                'itemid' => $itemid,
                'areaid' => $areaid,
                'title' => trim(\core_text::substr($hit->text, 0, 120)),
                'content' => $hit->text,
                'contextid' => (int)($hit->metadata['contextid'] ?? 0),
                'type' => (int)($hit->metadata['type'] ?? \core_search\manager::TYPE_TEXT),
                'courseid' => (int)($hit->metadata['courseid'] ?? SITEID),
                'owneruserid' => (int)($hit->metadata['owneruserid'] ?? \core_search\manager::NO_OWNER_ID),
                'modified' => (int)($hit->metadata['modified'] ?? time()),
                'userid' => $hit->metadata['userid'] ?? null,
                'groupid' => $hit->metadata['groupid'] ?? null,
            ];
            $results[] = $this->to_document($searcharea, $docdata);
            $seen[$hit->docid] = true;
            if (count($results) >= $limit) {
                break;
            }
        }

        $this->querytotalcount = count($results);
        return $results;
    }

    public function is_server_ready() {
        try {
            $ready = $this->get_store()->is_ready();
        } catch (\core_search\engine_exception $e) {
            return $e->getMessage();
        }
        if ($ready !== true) {
            return $ready;
        }
        if (!$this->embedder->is_embedding_available()) {
            return $this->embedder->get_last_error() ?? get_string('error:noproviders', 'core_ai');
        }
        if (!$this->is_selection_compatible_with_lock(
            $this->embedder->get_selected_provider_id(),
            $this->embedder->get_selected_model()
        )) {
            return get_string('embeddinglockmodelmismatch', 'search_semantic');
        }
        return true;
    }

    public function is_installed() {
        return true;
    }

    public function get_query_total_count() {
        return $this->querytotalcount;
    }

    public function delete_index_for_context(int $oldcontextid) {
        $this->get_store()->delete($this->get_default_collection(), filter::eq('contextid', $oldcontextid));
        return true;
    }

    public function delete_index_for_course(int $oldcourseid) {
        $this->get_store()->delete($this->get_default_collection(), filter::eq('courseid', $oldcourseid));
        return true;
    }

    /**
     * Whether the query-only alternate store settings are filled in.
     *
     * @return bool
     */
    public function has_alternate_configuration(): bool {
        return stores::is_configured($this->config, 'alternate');
    }

    /**
     * Split a document into fixed-size chunks.
     *
     * @param string $text
     * @return string[]
     */
    private function split_text(string $text): array {
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $chunklen = 800;
        $parts = [];
        $offset = 0;
        $length = \core_text::strlen($text);
        while ($offset < $length) {
            $parts[] = \core_text::substr($text, $offset, $chunklen);
            $offset += $chunklen;
        }
        return $parts;
    }

    /**
     * Resolve user id for internal AI calls.
     *
     * @return int
     */
    private function resolve_userid(): int {
        global $USER;
        if (!empty($USER->id)) {
            return (int)$USER->id;
        }
        return (int)get_admin()->id;
    }

    /**
     * Build metadata restrictions for vector queries.
     *
     * @param \stdClass $formdata
     * @param mixed $accessinfo
     * @return filter|null
     */
    private function build_query_filter(\stdClass $formdata, mixed $accessinfo): ?filter {
        $filters = [];
        $areaids = [];
        if (!empty($formdata->areaids)) {
            $areaids = array_values($formdata->areaids);
            $filters[] = filter::in('areaid', $areaids);
        }
        if (!empty($formdata->courseids)) {
            $filters[] = filter::in('courseid', array_values($formdata->courseids));
        }
        if (!empty($formdata->userids)) {
            $filters[] = filter::in('userid', array_values($formdata->userids));
        }

        if ($accessinfo !== true) {
            $areacontexts = [];
            if (is_array($accessinfo)) {
                $areacontexts = $accessinfo;
            } else if (is_object($accessinfo) && !empty($accessinfo->usercontexts)) {
                $areacontexts = $accessinfo->usercontexts;
            }
            $accessfilters = [];
            foreach ($areacontexts as $areaid => $contexts) {
                if ($areaids && !in_array($areaid, $areaids)) {
                    continue;
                }
                $contextids = array_values((array)$contexts);
                if (!$contextids) {
                    continue;
                }
                $accessfilters[] = filter::and(
                    filter::eq('areaid', (string)$areaid),
                    filter::in('contextid', $contextids),
                );
            }
            if ($accessfilters) {
                $filters[] = filter::or(...$accessfilters);
            } else {
                $filters[] = filter::in('contextid', []);
            }
        }

        if (!$filters) {
            return null;
        }
        return count($filters) === 1 ? $filters[0] : filter::and(...$filters);
    }

    /**
     * Parse a core search document id.
     *
     * @param string $docid
     * @return array{0:string,1:int}
     */
    private function split_docid(string $docid): array {
        if (!preg_match('/^(.*)-([0-9]+)$/', $docid, $matches)) {
            return ['', 0];
        }
        return [$matches[1], (int)$matches[2]];
    }

    /**
     * Build the collection object for a known vector dimension.
     *
     * @param int $dimensions
     * @return collection
     */
    private function get_collection(int $dimensions): collection {
        $model = (string)get_config('search_semantic', 'lockedembeddingmodel');
        if ($model === '') {
            $model = $this->embedder->get_selected_model();
        }
        return new collection('search_semantic_chunks', $dimensions, vectorstore\distance::cosine, $model);
    }

    /**
     * Build a collection for calls that do not know a vector yet.
     *
     * @return collection
     */
    private function get_default_collection(): collection {
        $dimensions = (int)get_config('search_semantic', 'lockedembeddingdimensions');
        if ($dimensions < 1) {
            $dimensions = 1536;
        }
        return $this->get_collection($dimensions > 0 ? $dimensions : 1536);
    }

    /**
     * Lock provider/model/dimensions once indexing starts.
     *
     * @param int $providerid
     * @param string $model
     * @param int $dimensions
     * @return void
     */
    private function lock_embedding_selection(int $providerid, string $model, int $dimensions): void {
        if (!empty(get_config('search_semantic', 'embeddinglocked'))) {
            return;
        }
        set_config('embeddinglocked', 1, 'search_semantic');
        set_config('lockedembeddingproviderid', $providerid, 'search_semantic');
        set_config('lockedembeddingmodel', $model, 'search_semantic');
        set_config('lockedembeddingdimensions', $dimensions, 'search_semantic');
    }

    /**
     * Validate provider/model against lock configuration when lock is active.
     *
     * @param int $providerid
     * @param string $model
     * @return bool
     */
    private function is_selection_compatible_with_lock(int $providerid, string $model): bool {
        if (empty(get_config('search_semantic', 'embeddinglocked'))) {
            return true;
        }
        return $providerid > 0
            && (int)get_config('search_semantic', 'lockedembeddingproviderid') === $providerid
            && (string)get_config('search_semantic', 'lockedembeddingmodel') === $model;
    }

    /**
     * Validate vector dimensions against lock configuration when lock is active.
     *
     * @param int $dimensions
     * @return bool
     */
    private function is_vector_dimension_compatible_with_lock(int $dimensions): bool {
        $lockeddimensions = (int)get_config('search_semantic', 'lockedembeddingdimensions');
        if ($lockeddimensions < 1) {
            return true;
        }
        return $dimensions === $lockeddimensions;
    }
}
