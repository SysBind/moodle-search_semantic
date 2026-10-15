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
 * Semantic search engine settings.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'search_semantic_pgvector',
        new lang_string('pgvectorheading', 'search_semantic'),
        new lang_string('pgvectorheading_desc', 'search_semantic'),
    ));
    $settings->add(new admin_setting_configtext(
        'search_semantic/pgvectorhost',
        new lang_string('pgvectorhost', 'search_semantic'),
        new lang_string('pgvectorhost_desc', 'search_semantic'),
        '127.0.0.1',
        PARAM_HOST,
    ));
    $settings->add(new admin_setting_configtext(
        'search_semantic/pgvectorport',
        new lang_string('pgvectorport', 'search_semantic'),
        new lang_string('pgvectorport_desc', 'search_semantic'),
        5432,
        PARAM_INT,
    ));
    $settings->add(new admin_setting_configtext(
        'search_semantic/pgvectordbname',
        new lang_string('pgvectordbname', 'search_semantic'),
        new lang_string('pgvectordbname_desc', 'search_semantic'),
        'moodle',
        PARAM_ALPHANUMEXT,
    ));
    $settings->add(new admin_setting_configtext(
        'search_semantic/pgvectoruser',
        new lang_string('pgvectoruser', 'search_semantic'),
        new lang_string('pgvectoruser_desc', 'search_semantic'),
        'postgres',
        PARAM_RAW_TRIMMED,
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'search_semantic/pgvectorpassword',
        new lang_string('pgvectorpassword', 'search_semantic'),
        new lang_string('pgvectorpassword_desc', 'search_semantic'),
        '',
    ));
}
