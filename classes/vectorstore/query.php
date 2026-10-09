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
 * Vector store query.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * A filtered nearest-neighbour query.
 */
final class query {

    /**
     * Constructor.
     *
     * @param float[] $vector Query embedding
     * @param int $limit Maximum number of hits
     * @param filter|null $filter Restriction on chunk metadata, null for none
     * @param float|null $minscore Drop hits scoring below this, null for no threshold
     */
    public function __construct(
        /** @var float[] Query embedding */
        public readonly array $vector,
        /** @var int Maximum number of hits */
        public readonly int $limit,
        /** @var filter|null Metadata restriction */
        public readonly ?filter $filter = null,
        /** @var float|null Score threshold */
        public readonly ?float $minscore = null,
    ) {
        if ($limit < 1) {
            throw new \coding_exception('Vector query limit must be positive');
        }
    }
}
