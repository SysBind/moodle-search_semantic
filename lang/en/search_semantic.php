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
 * Language strings.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Semantic Search';
$string['privacy:metadata'] = 'The Semantic Search plugin does not store personal data directly.';
$string['storeheading'] = 'Vector store';
$string['storeheading_desc'] = 'Database that holds embedding collections. Indexing uses the settings in this section. Queries use them as well, unless a query-only alternate configuration is selected on the Manage global search page.';
$string['storetype'] = 'Store type';
$string['storetype_desc'] = 'Vector database used for indexing and for search.';
$string['alternatestoretype_desc'] = 'Vector database used when this engine is the query-only search engine with alternate settings. Choose None to leave the alternate configuration unset.';
$string['storetypepgvector'] = 'pgvector';
$string['storetypenone'] = 'None';
$string['unknownstoretype'] = 'Unknown store type "{$a}"';
$string['embeddingproviderid'] = 'Embedding model';
$string['embeddingproviderid_desc'] = 'Select the configured AI provider/model pair used for semantic embeddings. This must be selected explicitly and is locked after indexing starts.';
$string['embeddingproviderselect'] = 'Select embedding model';
$string['embeddingprovidernoneavailable'] = 'No configured AI providers currently expose an enabled embed_text action with a model.';
$string['embeddingselectionlocked'] = 'Embedding selection is locked';
$string['embeddingselectionlocked_desc'] = 'Indexing has started. Provider ID: {$a->providerid}, model: {$a->model}, dimensions: {$a->dimensions}. To change model/dimensions, reset semantic index and clear lock settings.';
$string['embeddingproviderrequired'] = 'Select an embedding model provider in semantic search settings.';
$string['embeddingprovidernotfound'] = 'Selected embedding provider was not found.';
$string['embeddingproviderdisabled'] = 'Selected embedding provider is disabled.';
$string['embeddingprovidernotconfigured'] = 'Selected embedding provider is not fully configured.';
$string['embeddingproviderunsupported'] = 'Selected provider does not support embed_text.';
$string['embeddingprovideractiondisabled'] = 'embed_text is disabled on the selected provider.';
$string['embeddingmodelrequired'] = 'Selected provider has no embed_text model configured.';
$string['embeddingemptyresponse'] = 'Embedding provider returned an empty embedding vector.';
$string['embeddinglockmodelmismatch'] = 'Selected embedding model/provider does not match the locked indexing configuration.';
$string['embeddinglockdimensionmismatch'] = 'Embedding dimensions do not match the locked indexing configuration.';
$string['pgvectorheading'] = 'pgvector';
$string['pgvectorheading_desc'] = 'PostgreSQL database with the vector extension. Each embedding collection is a table in this database.';
$string['pgvectorhost'] = 'Host';
$string['pgvectorhost_desc'] = 'Hostname of the PostgreSQL server. Use db when Moodle runs in the project Docker environment.';
$string['pgvectorport'] = 'Port';
$string['pgvectorport_desc'] = 'PostgreSQL port. 5432 is the usual value.';
$string['pgvectordbname'] = 'Database';
$string['pgvectordbname_desc'] = 'Database name. The vector extension must already be installed in this database.';
$string['pgvectoruser'] = 'User';
$string['pgvectoruser_desc'] = 'Database user used for the vector tables.';
$string['pgvectorpassword'] = 'Password';
$string['pgvectorpassword_desc'] = 'Password for the database user.';
