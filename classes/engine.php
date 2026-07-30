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

defined('MOODLE_INTERNAL') || die();

/**
 * Bare skeleton semantic search engine.
 */
class engine extends \core_search\engine {

    public function add_document($document, $fileindexing = false) {
        return true;
    }

    public function delete_by_id($id) {
    }

    public function delete($areaid = null) {
    }

    public function execute_query($formdata, $usercontext = null, $limit = 0) {
        return [];
    }

    public function is_server_ready() {
        return true;
    }

    public function is_installed() {
        return true;
    }

    public function get_query_total_count() {
        return 0;
    }

    public function delete_index_for_context(int $oldcontextid) {
        return true;
    }

    public function delete_index_for_course(int $oldcourseid) {
        return true;
    }
}
