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
 * Vector store exception.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * Thrown by {@see store} implementations when the backend fails.
 *
 * Extends the core search engine exception so failures surface through the normal search error handling.
 */
class store_exception extends \core_search\engine_exception {

    /**
     * Constructor.
     *
     * @param string $debuginfo Backend error details
     */
    public function __construct(string $debuginfo) {
        parent::__construct('engineserverstatus', 'search', '', null, $debuginfo);
    }
}
