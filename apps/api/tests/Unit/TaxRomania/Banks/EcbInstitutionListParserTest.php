<?php

declare(strict_types=1);

namespace Tests\Unit\TaxRomania\Banks\EcbInstitutionListParserTest;

use Kern\TaxRomania\Banks\Parsing\EcbInstitutionListParser;
use Kern\TaxRomania\Banks\Parsing\Exceptions\InvalidBankSourceFileException;

const HEADER = "RIAD_CODE\tLEI\tCOUNTRY_OF_REGISTRATION\tNAME\tBOX\tADDRESS\tPOSTAL\tCITY\tCATEGORY\tHEAD_COUNTRY_OF_REGISTRATION\tHEAD_NAME\tHEAD_RIAD_CODE\tHEAD_LEI\tREPORT";

/** Encoded the way the ECB publishes it: UTF-16 little-endian with a byte-order mark, gzipped. */
function published(string $tsv): string
{
    return (string) gzencode("\xFF\xFE" . mb_convert_encoding($tsv, 'UTF-16LE', 'UTF-8'));
}

it('reads the published file: gzipped UTF-16, tab-separated, one institution a line', function (): void {
    $tsv = HEADER . "\r\n"
        . "RORBPJS40024\t\tRO\tING Bank N.V., Amsterdam - Bucharest Branch\t\tSTRADA AV. POPISTEANU, NR. 54A\t012095\tBUCURESTI\tCredit Institution\tNL\tING Bank N.V.\tNL0000\t3TK20IVIUJ8J3ZU0QE75\t\r\n"
        . "RORBPJR40046\t2138008AVF4W7FMW8W87\tRO\tCEC BANK S.A.\t\tCalea Victoriei nr.13\t030022\tBUCURESTI\tCredit Institution\t\t\t\t\t\r\n";

    $institutions = (new EcbInstitutionListParser())->parse(published($tsv));

    expect($institutions)->toHaveCount(2)
        ->and($institutions[0]->ecbCode)->toBe('RORBPJS40024')
        ->and($institutions[0]->lei)->toBe('')
        ->and($institutions[0]->parentName)->toBe('ING Bank N.V.')
        ->and($institutions[0]->parentLei)->toBe('3TK20IVIUJ8J3ZU0QE75')
        ->and($institutions[0]->parentCountryCode)->toBe('NL')
        // A value in a column the branch left empty is read in its own place, not shifted left.
        ->and($institutions[1]->lei)->toBe('2138008AVF4W7FMW8W87');
});

it('refuses a file missing a column the import relies on', function (): void {
    (new EcbInstitutionListParser())->parse(published("RIAD_CODE\tNAME\r\nRORBPJR40046\tCEC BANK S.A.\r\n"));
})->throws(InvalidBankSourceFileException::class, 'COUNTRY_OF_REGISTRATION');
