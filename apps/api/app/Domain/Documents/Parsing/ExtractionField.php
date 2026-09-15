<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing;

/**
 * One field a document type is expected to yield, and the shape it has.
 *
 * A field used to be a bare string in one of two lists, `required` and `optional`. That could say
 * *which* facts a document must supply but never *how they are arranged*, and an invoice's lines
 * are arranged: seven values, repeated once per article. There was nowhere in a list of strings to
 * put that, so the prompt could not ask for lines, the JSON schema could not describe them, and the
 * validator would have discarded them if a model had volunteered them anyway.
 *
 * All three of those are generated from this type, so a field described once is asked for, enforced
 * and kept consistently — the coupling that stops the words in the prompt drifting from the shape
 * the validator accepts.
 */
final readonly class ExtractionField
{
    /**
     * @param  list<self>  $children  the group's own fields; empty for a scalar
     */
    private function __construct(
        public string $name,
        public bool $required,
        public ExtractionFieldKind $kind,
        public array $children = [],
    ) {
    }

    public static function scalar(string $name, bool $required = false): self
    {
        return new self($name, $required, ExtractionFieldKind::SCALAR);
    }

    /**
     * A group of fields about one thing.
     *
     * A group is never itself required: `issuer` being absent is a document that did not print its
     * issuer, which is ordinary. Its *children* carry their own requiredness, and those only apply
     * to a group that is present at all — an issuer block that appears must name someone, but no
     * block is not a failure.
     *
     * @param  list<self>  $children
     */
    public static function object(string $name, array $children): self
    {
        return new self($name, false, ExtractionFieldKind::OBJECT, $children);
    }

    /**
     * Zero or more of a group.
     *
     * Also never required, for the same reason plus one more: a document's line items are what the
     * document has, and a receipt with no itemisation is not a failed reading. An entry that *is*
     * present is held to its children's requiredness, so a line with no amount is dropped rather
     * than stored as a line that means nothing.
     *
     * @param  list<self>  $children
     */
    public static function listOf(string $name, array $children): self
    {
        return new self($name, false, ExtractionFieldKind::LIST, $children);
    }
}
