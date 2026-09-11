<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing;

use App\Domain\Documents\Enums\DocumentType;

/**
 * Tries each registered parser in order and stops at the first that recognises the document.
 *
 * Order is priority: a parser written for one bank's statement layout should be offered the text
 * before a generic one, because a specific reading beats a plausible one. The list lives in
 * `DocumentsServiceProvider`, so adding a parser is a code change and a review — the same rule
 * `OperationTask` follows for runnable commands.
 *
 * A non-match is not a failure. Most documents will have no parser for a long time, and "nobody can
 * read this automatically" is an ordinary answer: the person opens it and types what they see, with
 * the document attached as provenance either way.
 */
final readonly class ParserRegistry
{
    /**
     * @param  list<DocumentParser>  $parsers  in priority order
     */
    public function __construct(private array $parsers)
    {
    }

    /**
     * The first parser's reading of this document, or a non-match.
     *
     * Output is checked against the type's `ExtractionSchema` before being returned, and a reading
     * that misses a required field is discarded rather than trimmed to fit — `BLUEPRINT.md` §14.4's
     * "invalid schema = failure, not best-effort save". A half-read receipt shown as a proposal
     * claims a machine understood the document when it did not.
     */
    public function parse(string $text, DocumentType $type): ParseResult
    {
        $schema = ExtractionSchema::for($type);

        if ($schema === null) {
            // No agreed shape for this type yet, so nothing may be proposed for it.
            return ParseResult::noMatch();
        }

        foreach ($this->parsers as $parser) {
            if (! $parser->supports($type)) {
                continue;
            }

            $result = $parser->parse($text, $type);

            if (! $result->matched) {
                continue;
            }

            if ($schema->missing($result->toFieldMap()) !== []) {
                // This parser recognised the layout but could not read what the type requires. Try
                // the next one rather than storing a partial reading.
                continue;
            }

            return $result;
        }

        return ParseResult::noMatch();
    }
}
