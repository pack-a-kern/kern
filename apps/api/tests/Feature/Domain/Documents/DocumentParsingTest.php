<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\DocumentParsingTest;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Parsing\ExtractionSchema;
use App\Domain\Documents\Parsing\ParserRegistry;
use App\Domain\Documents\Parsing\Parsers\RomanianReceiptParser;

/**
 * Reading a Romanian receipt without a model.
 *
 * The cases that matter are the ones where a plausible-looking wrong answer is possible: the comma
 * decimal, the running subtotal above the real total, and a page that merely contains a number.
 */
/**
 * The fields a receipt parser reads out of `$text`.
 *
 * @return array<string, string>
 */
function parseReceipt(string $text): array
{
    return (new RomanianReceiptParser())->parse($text, DocumentType::RECEIPT)->toFieldMap();
}

it('reads the total, the date and the fiscal code off a receipt', function (): void {
    $fields = parseReceipt(<<<'TXT'
        KAUFLAND ROMANIA SCS
        C.I.F.: RO17205554
        BON FISCAL
        NR. 0042
        Paine                        4,50
        Lapte                        8,90
        TOTAL                       13,40
        T.V.A. 9%                     1,11
        04.03.2026 14:22
        TXT);

    expect($fields[ExtractionSchema::FIELD_AMOUNT])->toBe('13.40')
        ->and($fields[ExtractionSchema::FIELD_DATE])->toBe('2026-03-04')
        ->and($fields[ExtractionSchema::FIELD_ISSUER_TAX_ID])->toBe('RO17205554')
        ->and($fields[ExtractionSchema::FIELD_MERCHANT])->toBe('KAUFLAND ROMANIA SCS');
});

it('reads a comma decimal with a dot thousands separator', function (): void {
    // 1.234,56 lei is one thousand two hundred and thirty-four. Reading the dot as the decimal
    // point would turn it into 1,23 lei — a plausible number, off by a factor of a thousand.
    $fields = parseReceipt("FACTURA\nC.I.F. RO123456\nTOTAL DE PLATA 1.234,56 LEI\n04.03.2026");

    expect($fields[ExtractionSchema::FIELD_AMOUNT])->toBe('1234.56');
});

it('takes the last total, not a running subtotal above it', function (): void {
    // Receipts print subtotals. Taking the first labelled amount would under-report every time.
    $fields = parseReceipt("BON FISCAL\nC.I.F. RO1\nSUBTOTAL 10,00\nTOTAL 25,00\n04.03.2026");

    expect($fields[ExtractionSchema::FIELD_AMOUNT])->toBe('25.00');
});

it('refuses a two-digit year rather than guessing the century', function (): void {
    // 01.02.03 has three plausible readings, and a wrong one moves income into another tax year.
    $fields = parseReceipt("BON FISCAL\nC.I.F. RO1\nTOTAL 25,00\n01.02.03");

    expect($fields)->not->toHaveKey(ExtractionSchema::FIELD_DATE);
});

it('does not recognise a document that is merely a page with a number on it', function (): void {
    // Without the Romanian fiscal vocabulary this is not a receipt. Extracting "a number and a
    // date" from an arbitrary page and calling it one is how a parser becomes untrustworthy.
    $result = (new RomanianReceiptParser())->parse(
        "Raport lunar de activitate\nTOTAL ore 25,00\n04.03.2026",
        DocumentType::RECEIPT,
    );

    expect($result->matched)->toBeFalse();
});

it('discards a reading that misses a field the schema requires', function (): void {
    $registry = new ParserRegistry([new RomanianReceiptParser()]);

    // A receipt with no readable date. The schema requires one, so this is a failed reading rather
    // than a partial one — storing it would put a half-filled form in front of a person as though
    // a machine had understood the document (`BLUEPRINT.md` §14.4).
    $result = $registry->parse("BON FISCAL\nC.I.F. RO1\nTOTAL 25,00\n", DocumentType::RECEIPT);

    expect($result->matched)->toBeFalse();
});

it('proposes nothing for a document type with no agreed schema', function (): void {
    $registry = new ParserRegistry([new RomanianReceiptParser()]);

    // A bank statement yields many transactions, not one, and needs a different shape entirely.
    // Until that is decided, nothing may be proposed for it.
    expect($registry->parse("BON FISCAL\nTOTAL 25,00\n04.03.2026", DocumentType::BANK_STATEMENT)->matched)
        ->toBeFalse();
});

it('keeps evidence for every field it read', function (): void {
    $result = (new RomanianReceiptParser())->parse(
        "BON FISCAL\nC.I.F. RO1\nTOTAL 25,00\n04.03.2026",
        DocumentType::RECEIPT,
    );

    // A proposal a reviewer can check without opening the document: the line each value came from.
    foreach ($result->fields as $field) {
        expect($field->evidence)->not->toBe('');
    }
});
