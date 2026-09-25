<?php

declare(strict_types=1);

namespace Kern\Documents\Extraction\DataTransferObjects;

use Stringable;

/**
 * Mirrors `apps/processing`'s `processing.documents.UntrustedDocumentText`
 * (`docs/handoff/019-pdf-text-extraction.md`): extracted document content is untrusted DATA,
 * never instructions (`BLUEPRINT.md` §14.5). `HttpTextExtractionGateway::parse()` wraps every page's
 * `{"kind": "untrusted_document_text", "content": ...}` payload into one of these at the wire
 * boundary — the same place the Python side's own marker is produced — so nothing downstream ever
 * holds a bare `string` that looks like ordinary trusted data.
 *
 * Deliberately not `Stringable`-transparent in the sense of exposing the content: `(string)
 * $wrapped` and string interpolation both render the redacted marker below, matching the Python
 * type's `__repr__` contract. Getting the raw text requires the explicit, grep-able `reveal()`
 * call, which every caller must treat as a data field crossing an audited boundary (write to
 * encrypted storage, a clearly-delimited field on an external-AI request) — never as an
 * instruction to obey and never concatenated into a prompt/system message.
 */
final readonly class UntrustedDocumentText implements Stringable
{
    private function __construct(private string $content)
    {
    }

    public static function wrap(string $content): self
    {
        return new self($content);
    }

    /**
     * Joins several pages' text into one document-level value, in order, matching the blank-line
     * join `apps/processing` itself uses between pages.
     *
     * @param list<self> $parts
     */
    public static function join(array $parts): self
    {
        return new self(implode("\n\n", array_map(
            static fn (self $part): string => $part->content,
            $parts,
        )));
    }

    /** The one explicit, grep-able way raw document content leaves this type. */
    public function reveal(): string
    {
        return $this->content;
    }

    public function equals(self $other): bool
    {
        return $this->content === $other->content;
    }

    public function __toString(): string
    {
        return sprintf('UntrustedDocumentText(<%d chars, redacted>)', mb_strlen($this->content));
    }
}
