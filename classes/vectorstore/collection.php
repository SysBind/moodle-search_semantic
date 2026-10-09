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
 * Vector collection definition.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * A named set of chunks embedded by a single model (a table in pgvector, a collection in Qdrant).
 */
final class collection {

    /**
     * Constructor.
     *
     * @param string $name Backend identifier, lowercase letters, digits and underscores
     * @param int $dimensions Vector length produced by the embedding model
     * @param distance $distance Similarity metric
     * @param string $model Embedding model identifier, informational, lets the engine detect model changes
     */
    public function __construct(
        /** @var string Backend identifier */
        public readonly string $name,
        /** @var int Vector length */
        public readonly int $dimensions,
        /** @var distance Similarity metric */
        public readonly distance $distance = distance::cosine,
        /** @var string Embedding model identifier */
        public readonly string $model = '',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name)) {
            throw new \coding_exception('Invalid vector collection name: ' . $name);
        }
        if ($dimensions < 1) {
            throw new \coding_exception('Vector collection dimensions must be positive');
        }
    }
}
