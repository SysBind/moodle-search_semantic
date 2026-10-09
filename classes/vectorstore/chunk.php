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
 * Vector store chunk.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

use core_search\document;

/**
 * One embedded piece of a search document.
 *
 * A {@see document} is split into one or more chunks, each with its own vector. All chunks of a
 * document share the document's metadata, which the store keeps as filterable fields.
 */
final class chunk {

    /**
     * Metadata fields every store must persist and be able to filter on.
     *
     * Kept fixed (rather than free-form metadata) so backends can map them to real, indexed
     * columns / payload indexes. Names match {@see document} fields.
     */
    public const FILTERABLE_FIELDS = [
        'docid', 'areaid', 'itemid', 'contextid', 'courseid', 'owneruserid', 'userid', 'groupid', 'type', 'modified',
    ];

    /**
     * Constructor.
     *
     * @param string $docid Search document id (areaid-itemid)
     * @param int $index Position of the chunk within the document, from 0
     * @param float[] $vector Embedding
     * @param string $text Chunk text, returned with hits for display and RAG prompts
     * @param array $metadata Values for {@see FILTERABLE_FIELDS}, except docid; nullable fields may be null
     */
    public function __construct(
        /** @var string Search document id */
        public readonly string $docid,
        /** @var int Position within the document */
        public readonly int $index,
        /** @var float[] Embedding */
        public readonly array $vector,
        /** @var string Chunk text */
        public readonly string $text,
        /** @var array Filterable metadata, without docid */
        public readonly array $metadata,
    ) {
        if ($unknown = array_diff(array_keys($metadata), self::FILTERABLE_FIELDS)) {
            throw new \coding_exception('Unknown chunk metadata fields: ' . implode(', ', $unknown));
        }
    }

    /**
     * Builds a chunk carrying the metadata of a search document.
     *
     * @param document $doc
     * @param int $index Position of the chunk within the document
     * @param float[] $vector Embedding of $text
     * @param string $text Chunk text
     * @return self
     */
    public static function from_document(document $doc, int $index, array $vector, string $text): self {
        $metadata = [];
        foreach (self::FILTERABLE_FIELDS as $field) {
            if ($field !== 'docid') {
                $metadata[$field] = $doc->is_set($field) ? $doc->get($field) : null;
            }
        }
        return new self($doc->get('id'), $index, $vector, $text, $metadata);
    }

    /**
     * Unique chunk id within a collection. Backends needing numeric or UUID keys should derive them from it.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->docid . '#' . $this->index;
    }
}
