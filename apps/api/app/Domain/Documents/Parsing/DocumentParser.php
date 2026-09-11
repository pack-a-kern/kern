<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing;

use App\Domain\Documents\Enums\DocumentType;

/**
 * Reads structured fields out of one shape of document.
 *
 * ## Why this exists instead of waiting for a model
 *
 * A receipt is four facts — amount, date, merchant, currency — and a bank statement is a table.
 * Both have layouts. A parser written for a layout is reproducible from the same bytes forever,
 * needs no consent, no network call and no provider, and for the layouts it knows is simply more
 * accurate than a model. Everything downstream — the review step, the provenance, the transaction —
 * works identically whether a parser or a model proposed the fields, so the pipeline tries parsers
 * first and is not blocked on anything.
 *
 * ## The contract
 *
 * `supports()` is a cheap check on the document type. `parse()` gets the extracted text and either
 * recognises it or returns {@see ParseResult::noMatch()} — a parser must never guess. Text from a
 * document is untrusted input (`BLUEPRINT.md` §14.5): implementations read it with patterns and
 * never interpret it as instructions.
 *
 * Every implementation is registered in `ParserRegistry`, which tries them in order and stops at
 * the first match.
 */
interface DocumentParser
{
    /** A stable identifier recorded on the extraction row, e.g. `receipt.ro.generic`. */
    public function name(): string;

    /**
     * Bumped whenever the patterns change.
     *
     * Stored beside each proposal so a figure produced by an older reading can be told from a newer
     * one — the same reason `TaxRule` versions exist.
     */
    public function version(): string;

    public function supports(DocumentType $type): bool;

    public function parse(string $text, DocumentType $type): ParseResult;
}
