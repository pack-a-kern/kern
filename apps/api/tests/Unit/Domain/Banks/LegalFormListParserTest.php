<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banks\LegalFormListParserTest;

use Kern\TaxRomania\Banks\Parsing\LegalFormListParser;

const HEADER = '"ELF Code","Country Code (ISO 3166-1)","Entity Legal Form name Local name","Entity Legal Form name Transliterated name (per ISO 01-140-10)","Abbreviations Local language","Abbreviations transliterated"';

it('matches Romania’s SA, which the ISO file writes with a non-breaking space after it', function (): void {
    $forms = (new LegalFormListParser())->parse(HEADER . "\n" . "\"ANDT\",\"RO\",\"Societate pe acţiuni\",\"\",\"SA\u{00A0}\",\"\"\n");

    expect($forms->lengthAt(['BANCA', 'TRANSILVANIA', 'S.A.'], 2, ['RO']))->toBe(1);
});

it('matches a form written out in full, and prefers it to an abbreviation it begins with', function (): void {
    $forms = (new LegalFormListParser())->parse(HEADER . "\n"
        . "\"BKUX\",\"HU\",\"Zártkörűen működő részvénytársaság\",\"\",\"Zrt.\",\"\"\n");

    $words = ['Bank', 'of', 'China', 'Zártkörűen', 'Működő', 'Részvénytársaság'];

    expect($forms->lengthAt($words, 3, ['HU']))->toBe(3)
        ->and($forms->lengthAt(['Bank', 'Zrt.'], 1, ['HU']))->toBe(1)
        // A country's forms do not cut another country's names.
        ->and($forms->lengthAt($words, 3, ['RO']))->toBe(0);
});

it('reads every abbreviation in a cell, and skips a description posing as one', function (): void {
    $forms = (new LegalFormListParser())->parse(HEADER . "\n"
        . "\"54M6\",\"NL\",\"naamloze vennootschap\",\"\",\"NV;nv;N.V.;n.v.\",\"\"\n"
        . "\"MNQ7\",\"IE\",\"Designated Activity Company\",\"\",\"DAC – (limited by guarantee)\",\"\"\n");

    expect($forms->lengthAt(['Nexent', 'Bank', 'n.v.'], 2, ['NL']))->toBe(1)
        ->and($forms->lengthAt(['X', 'DAC'], 1, ['IE']))->toBe(0);
});
