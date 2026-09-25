<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Banks\ImportBanksTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Legislation\Models\OfficialSource;
use Kern\TaxRomania\Banks\Actions\ImportBanks;
use Kern\TaxRomania\Banks\Models\Bank;
use Kern\TaxRomania\Banks\Parsing\Exceptions\InvalidBankSourceFileException;

/**
 * The whole import against faked publishers: three pages, three files, one table.
 *
 * Each fixture mirrors a real row seen on 2026-09-14/15 — Banca Transilvania with its own LEI, ING's
 * Romanian branch with none, CEC with an LEI GLEIF maps no BIC to — plus rows the import must ignore:
 * a Bulgarian bank and Romania's central bank.
 */
const ECB_PAGE = 'https://ecb-fixture.test/list.html';

const BIC_PAGE = 'https://gleif-fixture.test/bic.html';

const LEGAL_FORMS_PAGE = 'https://gleif-fixture.test/elf.html';

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.storage.disk' => 's3',
        'sources.storage.prefix' => 'sources',
        'sources.entries.ECB.allowed_hosts' => ['ecb-fixture.test'],
        'sources.entries.ECB.endpoints.institution_list_page' => ECB_PAGE,
        'sources.entries.GLEIF.allowed_hosts' => ['gleif-fixture.test'],
        'sources.entries.GLEIF.endpoints.bic_lei_page' => BIC_PAGE,
        'sources.entries.GLEIF.endpoints.legal_forms_page' => LEGAL_FORMS_PAGE,
        'banks.countries' => ['RO'],
        'banks.categories' => ['Credit Institution'],
    ]);
});

/** @param list<string> $rows tab-separated institution rows */
function ecbList(array $rows): string
{
    $header = "RIAD_CODE\tLEI\tCOUNTRY_OF_REGISTRATION\tNAME\tBOX\tADDRESS\tPOSTAL\tCITY\tCATEGORY\tHEAD_COUNTRY_OF_REGISTRATION\tHEAD_NAME\tHEAD_RIAD_CODE\tHEAD_LEI\tREPORT";

    return (string) gzencode("\xFF\xFE" . mb_convert_encoding(implode("\r\n", [$header, ...$rows]) . "\r\n", 'UTF-16LE', 'UTF-8'));
}

function zipOf(string $name, string $content): string
{
    $data = (string) gzdeflate($content);
    $crc = (int) hexdec(hash('crc32b', $content));

    $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 8, 0, 0, $crc, strlen($data), strlen($content), strlen($name), 0) . $name . $data;
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 8, 0, 0, $crc, strlen($data), strlen($content), strlen($name), 0, 0, 0, 0, 0, 0) . $name;

    return $local . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
}

const BT = "RORBPJR12019\t549300RG3H390KEL8896\tRO\tBANCA TRANSILVANIA S.A.\t\tCalea Dorobantilor 30-36\t400117\tCLUJ-NAPOCA\tCredit Institution\t\t\t\t\t";

const ING = "RORBPJS40024\t\tRO\tING Bank N.V., Amsterdam - Bucharest Branch\t\tStr. Av. Popisteanu 54A\t012095\tBUCURESTI\tCredit Institution\tNL\tING Bank N.V.\tNL0000\t3TK20IVIUJ8J3ZU0QE75\t";

const CEC = "RORBPJR40046\t2138008AVF4W7FMW8W87\tRO\tCEC BANK S.A.\t\tCalea Victoriei nr.13\t030022\tBUCURESTI\tCredit Institution\t\t\t\t\t";

const OTHER_COUNTRY = "BGBANK000001\t\tBG\tUNITED BULGARIAN BANK EAD\t\t\t\tSOFIA\tCredit Institution\t\t\t\t\t";

const CENTRAL_BANK = "ROR361684\t259400VHILGGKLBNL383\tRO\tNational Bank of Romania\t\t\t\tBUCURESTI\tCentral Bank\t\t\t\t\t";

/**
 * What the ECB list currently holds. A later `Http::fake()` for the same address does not replace an
 * earlier one — the first registered stub keeps answering — so the file's response reads this instead
 * of capturing the rows it was registered with.
 *
 * @param  list<string>|null  $rows  the rows to publish from now on; null to read them
 * @return list<string>
 */
function listed(?array $rows = null): array
{
    static $current = [];

    if ($rows !== null) {
        $current = $rows;
    }

    return $current;
}

/** @param list<string> $rows */
function publishers(array $rows): void
{
    listed($rows);

    Http::fake([
        ECB_PAGE => Http::response('<a href="/dla/mfi_csv_260914.csv.gz">mfi_csv_260914.csv.gz</a>'),
        'https://ecb-fixture.test/dla/mfi_csv_260914.csv.gz' => static fn () => Http::response(ecbList(listed())),
        BIC_PAGE => Http::response('<a href="https://gleif-fixture.test/api/v2/bic-lei/fc357d54/download">LEI-BIC-20260828.zip</a>'),
        'https://gleif-fixture.test/api/v2/bic-lei/fc357d54/download' => Http::response(zipOf(
            'lei-bic-20260828.csv',
            // ING's Romanian code sits under ING Bank N.V., beside its Dutch one.
            "LEI,BIC\n549300RG3H390KEL8896,BTRLRO22XXX\n3TK20IVIUJ8J3ZU0QE75,INGBNL2AXXX\n3TK20IVIUJ8J3ZU0QE75,INGBROBUXXX\n",
        )),
        LEGAL_FORMS_PAGE => Http::response('<a href="https://gleif-fixture.test/files/2026-02-19-elf-code-list-v1.6.csv">CSV</a>'),
        'https://gleif-fixture.test/files/2026-02-19-elf-code-list-v1.6.csv' => Http::response(
            "\"ELF Code\",\"Country Code (ISO 3166-1)\",\"Entity Legal Form name Local name\",\"Entity Legal Form name Transliterated name (per ISO 01-140-10)\",\"Abbreviations Local language\",\"Abbreviations transliterated\"\n"
            // Romania's abbreviation exactly as the real file writes it, non-breaking space included.
            . "\"ANDT\",\"RO\",\"Societate pe acţiuni\",\"\",\"SA\u{00A0}\",\"\"\n"
            . "\"54M6\",\"NL\",\"naamloze vennootschap\",\"\",\"NV;nv;N.V.;n.v.\",\"\"\n",
        ),
    ]);
}

it('keeps the configured country’s credit institutions, named, with their codes', function (): void {
    publishers([BT, ING, CEC, OTHER_COUNTRY, CENTRAL_BANK]);

    $summary = app(ImportBanks::class)();

    expect($summary->listed)->toBe(3)
        ->and($summary->created)->toBe(3)
        ->and($summary->withoutBic)->toBe(1)
        ->and($summary->listDate)->toBe('2026-09-14')
        ->and($summary->bicListDate)->toBe('2026-08-28');

    $bt = Bank::query()->where('ecb_code', 'RORBPJR12019')->firstOrFail();
    $ing = Bank::query()->where('ecb_code', 'RORBPJS40024')->firstOrFail();
    $cec = Bank::query()->where('ecb_code', 'RORBPJR40046')->firstOrFail();

    expect([$bt->name, $bt->code(), $bt->legal_name])->toBe(['Banca Transilvania', 'BTRL', 'BANCA TRANSILVANIA S.A.'])
        // Found through the parent's LEI, and the Romanian code rather than the Dutch one.
        ->and([$ing->name, $ing->bic, $ing->parent_name])->toBe(['ING Bank', 'INGBROBUXXX', 'ING Bank N.V.'])
        ->and([$cec->name, $cec->bic])->toBe(['CEC Bank', null])
        ->and(Bank::query()->where('country_code', 'BG')->exists())->toBeFalse();

    // Downloading is what puts the two publishers on the settings screen as "in use".
    expect(OfficialSource::query()->whereIn('code', ['ECB', 'GLEIF'])->count())->toBe(2);
});

it('marks a bank that left the list inactive, and keeps it', function (): void {
    publishers([BT, ING, CEC]);
    app(ImportBanks::class)();

    publishers([BT, CEC]);
    $summary = app(ImportBanks::class)();

    expect($summary->created)->toBe(0)
        ->and($summary->deactivated)->toBe(1)
        ->and(Bank::query()->where('ecb_code', 'RORBPJS40024')->value('active'))->toBeFalse()
        ->and(Bank::query()->count())->toBe(3);
});

it('refuses a list with none of the configured country’s banks, and changes nothing', function (): void {
    publishers([BT, ING]);
    app(ImportBanks::class)();

    // A publisher glitch that drops Romania must not read as every Romanian bank closing.
    publishers([OTHER_COUNTRY]);

    expect(fn () => app(ImportBanks::class)())->toThrow(InvalidBankSourceFileException::class);
    expect(Bank::query()->where('active', true)->count())->toBe(2);
});
