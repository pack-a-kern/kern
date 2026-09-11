<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing\Parsers;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Parsing\DocumentParser;
use App\Domain\Documents\Parsing\ExtractionSchema;
use App\Domain\Documents\Parsing\ParsedField;
use App\Domain\Documents\Parsing\ParseResult;

/**
 * Reads a Romanian fiscal receipt or invoice: the total, the date, who issued it.
 *
 * ## Why patterns rather than a model
 *
 * A `bon fiscal` has a fixed vocabulary — `TOTAL`, `TVA`, `C.I.F.`, `BON FISCAL` — printed by a
 * cash register that has followed the same layout for years. Matching that is reproducible from the
 * same bytes forever, costs nothing, needs no consent and no network call, and for these documents
 * beats a model on accuracy. When a figure has to be defended years later, "this line matched this
 * pattern" is an argument.
 *
 * ## Reading rules
 *
 * The **total** is the *last* `TOTAL`-labelled amount in the document. Receipts print running
 * subtotals and a `TVA` line, and the amount a person actually paid is the final one — taking the
 * first would systematically under-report. `SUBTOTAL` is excluded explicitly, since it contains the
 * word.
 *
 * **Romanian decimals use a comma**, and thousands separators appear as either. `1.234,56` and
 * `1234,56` and `1234.56` all mean the same amount; the normaliser decides by which separator comes
 * last. Everything leaves here as a decimal *string* — never a float, because these figures reach a
 * tax return.
 *
 * **Dates** are `dd.mm.yyyy` or `dd/mm/yyyy`, the two forms Romanian registers print, normalised to
 * ISO. A two-digit year is refused rather than guessed at: `01.02.03` has three plausible readings
 * and a wrong one silently moves income into another tax year.
 *
 * Text from a document is untrusted (`BLUEPRINT.md` §14.5). Everything below is pattern matching
 * over a string; nothing in it is ever interpreted as an instruction.
 */
final readonly class RomanianReceiptParser implements DocumentParser
{
    /**
     * Words that mark the amount actually paid. `SUBTOTAL` is deliberately absent and actively
     * excluded — it contains `TOTAL` and would otherwise match.
     */
    private const string TOTAL_LABEL = '(?<!SUB)(?:TOTAL(?:\s+DE\s+PLAT[AĂ])?|SUMA\s+DE\s+PLAT[AĂ])';

    /** `1.234,56`, `1234,56`, `1234.56` — separators sorted out by `self::normaliseAmount()`. */
    private const string AMOUNT = '(\d{1,3}(?:[.\s]\d{3})*(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)';

    /** What makes this a Romanian receipt at all, rather than any document containing a number. */
    private const string RECEIPT_MARKERS = '/\b(BON\s+FISCAL|FACTUR[AĂ]|C\.?I\.?F\.?|COD\s+FISCAL|T\.?V\.?A\.?)\b/iu';

    public function name(): string
    {
        return 'receipt.ro.generic';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function supports(DocumentType $type): bool
    {
        return $type === DocumentType::RECEIPT || $type === DocumentType::INVOICE;
    }

    public function parse(string $text, DocumentType $type): ParseResult
    {
        if (! $this->supports($type)) {
            return ParseResult::noMatch();
        }

        // A document with none of the Romanian fiscal vocabulary is not this parser's shape. Saying
        // so beats extracting "a number and a date" from an arbitrary page and calling it a receipt.
        if (preg_match(self::RECEIPT_MARKERS, $text) !== 1) {
            return ParseResult::noMatch();
        }

        $fields = [];

        $amount = $this->findTotal($text);

        if ($amount !== null) {
            $fields[] = $amount;
        }

        $date = $this->findDate($text);

        if ($date !== null) {
            $fields[] = $date;
        }

        foreach ([$this->findTaxId($text), $this->findDocumentNumber($text), $this->findMerchant($text)] as $field) {
            if ($field !== null) {
                $fields[] = $field;
            }
        }

        // Matched the shape but read nothing useful: still a non-match, because a proposal with no
        // fields wastes a reviewer's time rather than saving it.
        return $fields === [] ? ParseResult::noMatch() : ParseResult::matched($this->name(), $this->version(), $fields);
    }

    private function findTotal(string $text): ?ParsedField
    {
        $pattern = '/' . self::TOTAL_LABEL . '\s*:?\s*(?:RON|LEI)?\s*' . self::AMOUNT . '\s*(?:RON|LEI)?/iu';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        // The last one: receipts print running subtotals above the amount actually paid.
        // `end()` rather than `array_key_last()`, whose nullable return the type system cannot
        // narrow here even though `preg_match_all` returning non-zero guarantees a non-empty list.
        $last = end($matches);

        if ($last === false) {
            return null;
        }

        $normalised = $this->normaliseAmount($last[1]);

        if ($normalised === null) {
            return null;
        }

        return new ParsedField(
            name: ExtractionSchema::FIELD_AMOUNT,
            value: $normalised,
            // High, but never 1.0: a labelled total on a recognised receipt is about as certain as
            // pattern matching gets, and certainty is still the reviewer's to grant.
            confidence: 0.95,
            evidence: trim($last[0]),
        );
    }

    private function findDate(string $text): ?ParsedField
    {
        // Four-digit years only. `01.02.03` has three plausible readings, and picking one silently
        // moves income into a different tax year.
        if (preg_match('/\b(\d{2})[.\/-](\d{2})[.\/-](\d{4})\b/', $text, $match) !== 1) {
            return null;
        }

        [$whole, $day, $month, $year] = $match;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return new ParsedField(
            name: ExtractionSchema::FIELD_DATE,
            value: sprintf('%s-%s-%s', $year, $month, $day),
            // Lower than the total: a receipt often prints several dates, and the first one on the
            // page is usually but not always the transaction's.
            confidence: 0.8,
            evidence: $whole,
        );
    }

    private function findTaxId(string $text): ?ParsedField
    {
        if (preg_match('/\b(?:C\.?I\.?F\.?|C\.?U\.?I\.?|COD\s+FISCAL)\s*:?\s*(RO)?\s*(\d{2,10})\b/iu', $text, $match) !== 1) {
            return null;
        }

        // Both groups are always present: the optional `RO` precedes a required group, so PCRE
        // fills it with an empty string rather than omitting it. `RO17205554` and `17205554` are
        // the same code written two ways, and the prefix is kept where the document printed it.
        return new ParsedField(
            name: ExtractionSchema::FIELD_ISSUER_TAX_ID,
            value: strtoupper($match[1] . $match[2]),
            confidence: 0.9,
            evidence: trim($match[0]),
        );
    }

    private function findDocumentNumber(string $text): ?ParsedField
    {
        if (preg_match('/\b(?:NR\.?|NUM[AĂ]R|SERIA)\s*:?\s*([A-Z0-9][A-Z0-9\-\/]{1,20})\b/iu', $text, $match) !== 1) {
            return null;
        }

        return new ParsedField(
            name: ExtractionSchema::FIELD_DOCUMENT_NUMBER,
            value: strtoupper($match[1]),
            confidence: 0.7,
            evidence: trim($match[0]),
        );
    }

    /**
     * The issuer's name, taken as the first non-empty line.
     *
     * Romanian receipts print the trading name at the very top, above the address and the fiscal
     * code. This is the least certain field here by a distance — hence the low confidence — but a
     * plausible name a reviewer can correct beats an empty box they must fill from scratch.
     */
    private function findMerchant(string $text): ?ParsedField
    {
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            // Skip the line if it is mostly digits: that is a till number or a date, not a name.
            if ($line === '' || mb_strlen($line) < 3 || preg_match('/^[\d\s.,\/-]+$/u', $line) === 1) {
                continue;
            }

            return new ParsedField(
                name: ExtractionSchema::FIELD_MERCHANT,
                value: mb_substr($line, 0, 120),
                confidence: 0.5,
                evidence: $line,
            );
        }

        return null;
    }

    /**
     * A Romanian-printed amount as a plain decimal string.
     *
     * The separator that appears *last* is the decimal one: `1.234,56` is comma-decimal,
     * `1,234.56` is dot-decimal, and `1.234` with no second separator is a thousands group. Guessing
     * the other way turns 1.234,56 lei into 1.23 lei, which is exactly the kind of silent error this
     * whole codebase is arranged to prevent.
     */
    private function normaliseAmount(string $raw): ?string
    {
        $raw = preg_replace('/\s+/u', '', $raw) ?? $raw;

        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '', $raw);

            // A lone dot with a three-digit group after it is a thousands separator, not decimals:
            // `1.234` is one thousand two hundred and thirty-four.
            if ($lastDot !== false && strlen($raw) - $lastDot - 1 === 3) {
                $raw = str_replace('.', '', $raw);
            }
        }

        return preg_match('/^\d+(\.\d{1,2})?$/', $raw) === 1 ? $raw : null;
    }
}
