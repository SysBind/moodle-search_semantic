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
 * Unit tests for bare skeleton search_semantic engine.
 *
 * @package    search_semantic
 * @category   test
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for bare skeleton search_semantic engine.
 */
class engine_test extends \advanced_testcase {

    public function test_engine_instantiation(): void {
        $this->resetAfterTest();
        set_config('storetype', 'pgvector', 'search_semantic');
        $engine = new \search_semantic\engine();
        $this->assertTrue($engine->is_installed());
    }

    /**
     * Alternate settings are reported only when filled in, and replace the indexing settings
     * on an engine constructed for the query-only configuration.
     */
    public function test_alternate_configuration(): void {
        $this->resetAfterTest();
        set_config('storetype', 'pgvector', 'search_semantic');

        $engine = new engine();
        $this->assertFalse($engine->has_alternate_configuration());

        set_config('storetype', 'pgvector', 'search_semantic');
        set_config('pgvectorhost', 'index.example', 'search_semantic');
        set_config('pgvectordbname', 'indexdb', 'search_semantic');
        set_config('pgvectoruser', 'indexuser', 'search_semantic');
        set_config('alternatestoretype', 'pgvector', 'search_semantic');

        $engine = new engine();
        $this->assertFalse($engine->has_alternate_configuration());

        set_config('alternatepgvectorhost', 'query.example', 'search_semantic');
        set_config('alternatepgvectordbname', 'querydb', 'search_semantic');
        set_config('alternatepgvectoruser', 'queryuser', 'search_semantic');

        $engine = new engine();
        $this->assertTrue($engine->has_alternate_configuration());
        $main = (new \ReflectionClass($engine))->getProperty('config')->getValue($engine);
        $this->assertSame('index.example', $main->pgvectorhost);
        $this->assertSame('pgvector', $main->storetype);

        $alternate = new engine(true);
        $query = (new \ReflectionClass($alternate))->getProperty('config')->getValue($alternate);
        $this->assertSame('query.example', $query->pgvectorhost);
        $this->assertSame('querydb', $query->pgvectordbname);
        $this->assertSame('queryuser', $query->pgvectoruser);
        $this->assertSame('pgvector', $query->storetype);
        $this->assertTrue(\search_semantic\vectorstore\stores::is_configured($query));
    }
}
