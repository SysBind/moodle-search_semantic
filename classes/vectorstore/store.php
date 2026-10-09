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
 * Vector database abstraction.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * A vector database backend (pgvector, Qdrant, ...).
 *
 * The store knows nothing about embedding models or Moodle access rules: it persists chunks
 * (vector + text + a fixed set of filterable metadata fields, see {@see chunk::FILTERABLE_FIELDS})
 * and runs filtered nearest-neighbour queries. The engine is responsible for chunking, embedding
 * and translating Moodle access information into a {@see filter}.
 *
 * Every operation is scoped to a {@see collection}. A collection is tied to one embedding model and
 * dimension, so switching models means indexing into a new collection and dropping the old one.
 *
 * Implementations must throw {@see store_exception} on backend failures.
 */
interface store {

    /**
     * Checks the backend is reachable and usable.
     *
     * @return true|string True if ready, otherwise a human readable error message
     */
    public function is_ready(): true|string;

    /**
     * Creates the collection and its indexes if they do not exist yet.
     *
     * Must be idempotent. If the collection already exists with a different dimension or distance,
     * the implementation must throw rather than silently reuse it.
     *
     * @param collection $collection
     */
    public function ensure_collection(collection $collection): void;

    /**
     * Removes the collection and all of its chunks. Does nothing if it does not exist.
     *
     * @param collection $collection
     */
    public function drop_collection(collection $collection): void;

    /**
     * Writes chunks, replacing whole documents.
     *
     * For every docid present in $chunks, all previously stored chunks of that document are
     * removed before the new ones are written, so a document that shrinks from 5 to 3 chunks does
     * not leave stale chunks behind. Implementations should make this atomic per call where the
     * backend allows it.
     *
     * @param collection $collection
     * @param chunk[] $chunks Vectors must have $collection->dimensions elements
     */
    public function upsert(collection $collection, array $chunks): void;

    /**
     * Deletes all chunks matching the filter.
     *
     * @param collection $collection
     * @param filter $filter Use {@see filter::all()} to empty the collection
     */
    public function delete(collection $collection, filter $filter): void;

    /**
     * Nearest-neighbour search.
     *
     * The query filter must be applied inside the index scan (pre-filtering), not on the top-k
     * results afterwards, otherwise restrictive access filters return too few results.
     *
     * @param collection $collection
     * @param query $query
     * @return hit[] Ordered by score, best first
     */
    public function search(collection $collection, query $query): array;
}
