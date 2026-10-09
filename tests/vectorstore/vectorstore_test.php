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
 * Unit tests for the vector store value objects.
 *
 * @package    search_semantic
 * @category   test
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * Unit tests for the vector store value objects.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(filter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(chunk::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(collection::class)]
final class vectorstore_test extends \advanced_testcase {

    public function test_filter_tree(): void {
        $filter = filter::and(
            filter::in('contextid', [5 => 5, 9 => 9]),
            filter::or(filter::is_null('groupid'), filter::eq('groupid', 3)),
        );

        $this->assertSame(filter::OP_AND, $filter->op);
        $this->assertCount(2, $filter->children);
        $this->assertSame([5, 9], $filter->children[0]->value);
        $this->assertSame(filter::OP_OR, $filter->children[1]->op);
        $this->assertSame(filter::OP_NULL, $filter->children[1]->children[0]->op);
    }

    public function test_filter_single_operand_collapses(): void {
        $leaf = filter::eq('areaid', 'mod_forum-post');
        $this->assertSame($leaf, filter::or($leaf));
    }

    public function test_filter_rejects_unknown_field(): void {
        $this->expectException(\coding_exception::class);
        filter::eq('content', 'x');
    }

    public function test_filter_rejects_empty_range(): void {
        $this->expectException(\coding_exception::class);
        filter::range('modified', null, null);
    }

    public function test_chunk_from_document(): void {
        $doc = new \core_search\document(12, 'mod_forum', 'post');
        $doc->set('title', 'Title');
        $doc->set('content', 'Content');
        $doc->set('contextid', 7);
        $doc->set('courseid', 3);
        $doc->set('owneruserid', \core_search\manager::NO_OWNER_ID);
        $doc->set('modified', 1000);

        $chunk = chunk::from_document($doc, 2, [0.1, 0.2], 'Content');

        $this->assertSame('mod_forum-post-12', $chunk->docid);
        $this->assertSame('mod_forum-post-12#2', $chunk->get_id());
        $this->assertSame('mod_forum-post', $chunk->metadata['areaid']);
        $this->assertEquals(7, $chunk->metadata['contextid']);
        $this->assertNull($chunk->metadata['groupid']);
        $this->assertArrayNotHasKey('docid', $chunk->metadata);
    }

    public function test_chunk_rejects_unknown_metadata(): void {
        $this->expectException(\coding_exception::class);
        new chunk('a-b-1', 0, [0.1], 'text', ['secret' => 1]);
    }

    public function test_collection_validation(): void {
        $collection = new collection('semantic_v1', 1536);
        $this->assertSame(distance::cosine, $collection->distance);

        $this->expectException(\coding_exception::class);
        new collection('Bad-Name', 1536);
    }
}
