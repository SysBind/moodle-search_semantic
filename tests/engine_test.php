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
        $engine = new \search_semantic\engine();
        $this->assertTrue($engine->is_installed());
    }
}
