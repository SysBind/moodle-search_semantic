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
 * Vector store search hit.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * A chunk matched by a {@see query}.
 */
final class hit {

    /**
     * Constructor.
     *
     * @param string $docid Search document id
     * @param int $index Chunk position within the document
     * @param float $score Similarity, higher is better whatever the collection distance
     * @param string $text Chunk text
     * @param array $metadata Stored values of {@see chunk::FILTERABLE_FIELDS}, without docid
     */
    public function __construct(
        /** @var string Search document id */
        public readonly string $docid,
        /** @var int Chunk position within the document */
        public readonly int $index,
        /** @var float Similarity, higher is better */
        public readonly float $score,
        /** @var string Chunk text */
        public readonly string $text,
        /** @var array Stored metadata */
        public readonly array $metadata,
    ) {
    }
}
