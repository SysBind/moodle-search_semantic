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
 * Wrapper around core AI embedding actions.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\local;

use core_ai\aiactions\embed_text;
use core_ai\manager;
use core_ai\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Embedding client used by the semantic search engine.
 */
class embedding_client {
    /** @var manager */
    private manager $manager;

    /** @var string|null */
    private ?string $lasterror = null;

    /** @var array|null */
    private ?array $lastmetadata = null;

    public function __construct() {
        $this->manager = \core\di::get(manager::class);
    }

    /**
     * Whether embed_text is available from the selected provider.
     *
     * @return bool
     */
    public function is_embedding_available(): bool {
        return $this->get_selected_provider() !== null;
    }

    /**
     * Return the latest availability/embedding error.
     *
     * @return string|null
     */
    public function get_last_error(): ?string {
        return $this->lasterror;
    }

    /**
     * Metadata for the last successful embedding call.
     *
     * @return array|null
     */
    public function get_last_metadata(): ?array {
        return $this->lastmetadata;
    }

    /**
     * Selected provider id from search_semantic config.
     *
     * @return int
     */
    public function get_selected_provider_id(): int {
        return (int)get_config('search_semantic', 'embeddingproviderid');
    }

    /**
     * Selected embed_text model from the chosen provider action settings.
     *
     * @return string
     */
    public function get_selected_model(): string {
        $provider = $this->get_selected_provider();
        if (!$provider) {
            return '';
        }
        return (string)($provider->actionconfig[embed_text::class]['settings']['model'] ?? '');
    }

    /**
     * Embed text and return the vector.
     *
     * @param string $text
     * @param int $contextid
     * @param int $userid
     * @return float[]|null
     */
    public function embed_text(string $text, int $contextid, int $userid): ?array {
        $this->lasterror = null;
        $this->lastmetadata = null;
        $provider = $this->get_selected_provider();
        if (!$provider) {
            return null;
        }

        // Process through core with the selected instance pinned: no failover to another
        // instance, since its vectors would not be comparable with what is already indexed.
        $action = new embed_text($contextid, $userid, $text);
        $response = $this->manager->process_action($action, (int)$provider->id);

        if (!$response->get_success()) {
            $this->lasterror = $response->get_errormessage();
            return null;
        }

        $data = $response->get_response_data();
        if (empty($data['embedding']) || !is_array($data['embedding'])) {
            $this->lasterror = get_string('embeddingemptyresponse', 'search_semantic');
            return null;
        }

        $vector = array_map('floatval', $data['embedding']);
        $this->lastmetadata = [
            'providerid' => $provider->id,
            'model' => (string)($provider->actionconfig[embed_text::class]['settings']['model'] ?? ''),
            'dimensions' => count($vector),
        ];
        return $vector;
    }

    /**
     * Resolve selected provider instance and ensure it supports embed_text.
     *
     * @return provider|null
     */
    private function get_selected_provider(): ?provider {
        $providerid = $this->get_selected_provider_id();
        if ($providerid < 1) {
            $this->lasterror = get_string('embeddingproviderrequired', 'search_semantic');
            return null;
        }

        $providers = $this->manager->get_provider_instances(['id' => $providerid]);
        $provider = reset($providers);
        if (!$provider) {
            $this->lasterror = get_string('embeddingprovidernotfound', 'search_semantic');
            return null;
        }
        if (!$provider->enabled) {
            $this->lasterror = get_string('embeddingproviderdisabled', 'search_semantic');
            return null;
        }
        if (!$provider->is_provider_configured()) {
            $this->lasterror = get_string('embeddingprovidernotconfigured', 'search_semantic');
            return null;
        }
        if (!in_array(embed_text::class, $provider->get_action_list())) {
            $this->lasterror = get_string('embeddingproviderunsupported', 'search_semantic');
            return null;
        }
        $actionconfig = $provider->actionconfig[embed_text::class] ?? null;
        if (empty($actionconfig['enabled'])) {
            $this->lasterror = get_string('embeddingprovideractiondisabled', 'search_semantic');
            return null;
        }
        if (empty($actionconfig['settings']['model'])) {
            $this->lasterror = get_string('embeddingmodelrequired', 'search_semantic');
            return null;
        }
        return $provider;
    }
}
