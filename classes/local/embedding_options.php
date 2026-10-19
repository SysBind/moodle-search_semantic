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
 * Embedding model options exposed in plugin settings.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\local;

use core_ai\aiactions\embed_text;
use core_ai\manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Build provider/model options for semantic embedding settings.
 */
class embedding_options {
    /**
     * Return selectable provider options for text embeddings.
     *
     * @return array
     */
    public static function get_provider_options(): array {
        $options = [];
        $aimanager = \core\di::get(manager::class);
        $providers = $aimanager->get_provider_instances();
        foreach ($providers as $provider) {
            if (!$provider->enabled || !$provider->is_provider_configured()) {
                continue;
            }
            if (!in_array(embed_text::class, $provider->get_action_list())) {
                continue;
            }
            $actionconfig = $provider->actionconfig[embed_text::class] ?? null;
            if (empty($actionconfig['enabled'])) {
                continue;
            }
            $model = (string)($actionconfig['settings']['model'] ?? '');
            if ($model === '') {
                continue;
            }
            $component = \core\component::get_component_from_classname($provider::class);
            if (get_string_manager()->string_exists('pluginname', $component)) {
                $providername = get_string('pluginname', $component);
            } else {
                $providername = $component;
            }
            $options[(string)$provider->id] = $provider->name . ' - ' . $providername . ' [' . $model . ']';
        }
        return $options;
    }
}
