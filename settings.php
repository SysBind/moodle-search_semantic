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
        'search_semantic_store',
        new lang_string('storeheading', 'search_semantic'),
        new lang_string('storeheading_desc', 'search_semantic'),
    ));

    $storetypes = [];
    foreach (\search_semantic\vectorstore\stores::options() as $type => $stringid) {
        $storetypes[$type] = new lang_string($stringid, 'search_semantic');
    }
    $settings->add(new admin_setting_configselect(
        'search_semantic/storetype',
        new lang_string('storetype', 'search_semantic'),
        new lang_string('storetype_desc', 'search_semantic'),
        \search_semantic\vectorstore\stores::PGVECTOR,
        $storetypes,
    ));
    \search_semantic\vectorstore\stores::add_admin_settings($settings, '', 'storetype');

    $settings->add(new admin_setting_heading(
        'search_semantic_alternatesettings',
        new lang_string('searchalternatesettings', 'admin'),
        new lang_string('searchalternatesettings_desc', 'admin'),
    ));
    $settings->add(new admin_setting_configselect(
        'search_semantic/alternatestoretype',
        new lang_string('storetype', 'search_semantic'),
        new lang_string('alternatestoretype_desc', 'search_semantic'),
        '',
        ['' => new lang_string('storetypenone', 'search_semantic')] + $storetypes,
    ));
    \search_semantic\vectorstore\stores::add_admin_settings($settings, 'alternate', 'alternatestoretype');
}
