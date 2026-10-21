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
 * Vector store backends shown on the admin page.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * Store types and the admin settings that configure them.
 *
 * A new backend adds an entry to {@see options()} and {@see configurations()}. The indexing
 * configuration uses the setting names as written. The query-only configuration prefixes every
 * name with "alternate", which {@see \core_search\engine} copies back over the plain names when
 * it is constructed for the alternate configuration.
 */
final class stores {

    /** @var string PostgreSQL with the vector extension. */
    public const PGVECTOR = 'pgvector';

    /**
     * Selectable store types.
     *
     * @return array<string, string> Config value => lang string key in search_semantic
     */
    public static function options(): array {
        return [
            self::PGVECTOR => 'storetypepgvector',
        ];
    }

    /**
     * Admin fields for each store type.
     *
     * A field is [setting name, label string, description string, default, PARAM_* or 'password'].
     * heading is [label string, description string]. required lists setting names that must be
     * non-empty before that configuration counts as filled in.
     *
     * @return array<string, array>
     */
    public static function configurations(): array {
        return [
            self::PGVECTOR => [
                'heading' => ['pgvectorheading', 'pgvectorheading_desc'],
                'required' => ['pgvectorhost', 'pgvectordbname', 'pgvectoruser'],
                'fields' => [
                    ['pgvectorhost', 'pgvectorhost', 'pgvectorhost_desc', '127.0.0.1', PARAM_HOST],
                    ['pgvectorport', 'pgvectorport', 'pgvectorport_desc', 5432, PARAM_INT],
                    ['pgvectordbname', 'pgvectordbname', 'pgvectordbname_desc', 'moodle', PARAM_ALPHANUMEXT],
                    ['pgvectoruser', 'pgvectoruser', 'pgvectoruser_desc', 'postgres', PARAM_RAW_TRIMMED],
                    ['pgvectorpassword', 'pgvectorpassword', 'pgvectorpassword_desc', '', 'password'],
                ],
            ],
        ];
    }

    /**
     * Whether the selected store's required connection fields are filled in.
     *
     * @param \stdClass $config Plugin config, already rewritten when this is the alternate engine
     * @param string $prefix '' for indexing settings, 'alternate' for the query-only settings
     * @return bool
     */
    public static function is_configured(\stdClass $config, string $prefix = ''): bool {
        $type = (string)($config->{$prefix . 'storetype'} ?? '');
        $configuration = self::configurations()[$type] ?? null;
        if ($configuration === null) {
            return false;
        }
        foreach ($configuration['required'] as $field) {
            if (trim((string)($config->{$prefix . $field} ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Adds one store type's fields, hidden unless that type is selected.
     *
     * @param \admin_settingpage $settings
     * @param string $prefix '' or 'alternate'
     * @param string $storetypesetting Setting that holds the selected type
     */
    public static function add_admin_settings(\admin_settingpage $settings, string $prefix, string $storetypesetting): void {
        foreach (self::configurations() as $type => $configuration) {
            [$heading, $headingdesc] = $configuration['heading'];
            $headingname = 'search_semantic_' . $prefix . $type;
            $settings->add(new \admin_setting_heading(
                $headingname,
                new \lang_string($heading, 'search_semantic'),
                new \lang_string($headingdesc, 'search_semantic'),
            ));
            $settings->hide_if($headingname, 'search_semantic/' . $storetypesetting, 'neq', $type);

            foreach ($configuration['fields'] as [$name, $label, $desc, $default, $param]) {
                $settingname = 'search_semantic/' . $prefix . $name;
                if ($prefix !== '') {
                    // The alternate (query-only) store is opt-in and must point somewhere else than the
                    // main store, so it gets no defaults: is_configured() stays false until filled in.
                    $default = '';
                }
                if ($param === 'password') {
                    $setting = new \admin_setting_configpasswordunmask(
                        $settingname,
                        new \lang_string($label, 'search_semantic'),
                        new \lang_string($desc, 'search_semantic'),
                        $default,
                    );
                } else {
                    $setting = new \admin_setting_configtext(
                        $settingname,
                        new \lang_string($label, 'search_semantic'),
                        new \lang_string($desc, 'search_semantic'),
                        $default,
                        $param,
                    );
                }
                $settings->add($setting);
                $settings->hide_if($settingname, 'search_semantic/' . $storetypesetting, 'neq', $type);
            }
        }
    }
}
