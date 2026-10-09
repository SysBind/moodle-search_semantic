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
 * Vector store metadata filter.
 *
 * @package    search_semantic
 * @copyright  2026 Asaf Ohayon <asaf@sysbind.co.il>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace search_semantic\vectorstore;

/**
 * Backend neutral boolean expression over {@see chunk::FILTERABLE_FIELDS}.
 *
 * Built with the static constructors and translated by each store into its own dialect (SQL WHERE,
 * Qdrant filter JSON, ...). Expressive enough for Moodle search access rules, e.g.
 * "(areaid = A AND contextid IN (...)) OR (...)" combined with owner and group restrictions.
 *
 * Stores walk the tree through {@see $op}, {@see $field}, {@see $value} and {@see $children}.
 */
final class filter {

    /** Matches every chunk. */
    public const OP_ALL = 'all';
    /** Field equals value. */
    public const OP_EQ = 'eq';
    /** Field equals one of a list of values. */
    public const OP_IN = 'in';
    /** Field within an inclusive range, value is [min|null, max|null]. */
    public const OP_RANGE = 'range';
    /** Field is null. */
    public const OP_NULL = 'null';
    /** All children match. */
    public const OP_AND = 'and';
    /** Any child matches. */
    public const OP_OR = 'or';
    /** The single child does not match. */
    public const OP_NOT = 'not';

    /**
     * Use the static constructors.
     *
     * @param string $op One of the OP_ constants
     * @param string|null $field Field for leaf operators
     * @param mixed $value Operand for leaf operators
     * @param filter[] $children Operands for and/or/not
     */
    private function __construct(
        /** @var string One of the OP_ constants */
        public readonly string $op,
        /** @var string|null Field for leaf operators */
        public readonly ?string $field = null,
        /** @var mixed Operand for leaf operators */
        public readonly mixed $value = null,
        /** @var filter[] Operands for and/or/not */
        public readonly array $children = [],
    ) {
        if ($field !== null && !in_array($field, chunk::FILTERABLE_FIELDS, true)) {
            throw new \coding_exception('Field is not filterable: ' . $field);
        }
    }

    /**
     * Matches every chunk.
     *
     * @return self
     */
    public static function all(): self {
        return new self(self::OP_ALL);
    }

    /**
     * Field equals value.
     *
     * @param string $field
     * @param int|string $value
     * @return self
     */
    public static function eq(string $field, int|string $value): self {
        return new self(self::OP_EQ, $field, $value);
    }

    /**
     * Field equals one of the values. An empty list matches nothing.
     *
     * @param string $field
     * @param array $values int|string values
     * @return self
     */
    public static function in(string $field, array $values): self {
        return new self(self::OP_IN, $field, array_values($values));
    }

    /**
     * Field within an inclusive range.
     *
     * @param string $field
     * @param int|null $min Null for unbounded
     * @param int|null $max Null for unbounded
     * @return self
     */
    public static function range(string $field, ?int $min, ?int $max): self {
        if ($min === null && $max === null) {
            throw new \coding_exception('Range filter needs at least one bound');
        }
        return new self(self::OP_RANGE, $field, [$min, $max]);
    }

    /**
     * Field is null (e.g. a document without a groupid).
     *
     * @param string $field
     * @return self
     */
    public static function is_null(string $field): self {
        return new self(self::OP_NULL, $field);
    }

    /**
     * All of the filters match.
     *
     * @param filter ...$filters
     * @return self
     */
    public static function and(filter ...$filters): self {
        return self::combine(self::OP_AND, $filters);
    }

    /**
     * Any of the filters matches.
     *
     * @param filter ...$filters
     * @return self
     */
    public static function or(filter ...$filters): self {
        return self::combine(self::OP_OR, $filters);
    }

    /**
     * The filter does not match.
     *
     * @param filter $filter
     * @return self
     */
    public static function not(filter $filter): self {
        return new self(self::OP_NOT, null, null, [$filter]);
    }

    /**
     * Builds an and/or node, collapsing the trivial single operand case.
     *
     * @param string $op
     * @param filter[] $filters
     * @return self
     */
    private static function combine(string $op, array $filters): self {
        if (!$filters) {
            throw new \coding_exception('Filter ' . $op . ' needs at least one operand');
        }
        if (count($filters) === 1) {
            return reset($filters);
        }
        return new self($op, null, null, array_values($filters));
    }
}
