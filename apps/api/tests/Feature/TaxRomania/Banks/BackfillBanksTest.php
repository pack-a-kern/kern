<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Banks\BackfillBanksTest;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\TaxRomania\Banks\Actions\BackfillBanks;
use Kern\TaxRomania\Banks\Exceptions\BankSourceNotConfiguredException;
use Kern\TaxRomania\Banks\Models\Bank;

/**
 * Closed banks, read back from the ECB's archive.
 *
 * Mirrors what the real archive held for Romania in the summer of 2025: Alpha Bank Romania still
 * listed on 1 July and 4 August, UniCredit beside it, and a list for 1 August that does not exist
 * because the archive has none for that day. The July file is in the pre-2019 shape, without LEIs.
 */
const ARCHIVE = 'https://ecb-fixture.test/archive/mfi_csv_{date}.csv.gz';

const BIC_PAGE = 'https://gleif-fixture.test/bic.html';

const LEGAL_FORMS_PAGE = 'https://gleif-fixture.test/elf.html';

const UNICREDIT = ['RORBPJR40011', '5493003BDYD5VPGUQS04', 'UNICREDIT BANK S.A.'];

const ALPHA = ['RORBPJR40022', '529900TKT32Z5LP7XF90', 'ALPHA BANK ROMANIA S.A.'];

const OLD_BANK = ['RORBPJR49999', '529900OLDBANK0000001', 'BANCA VECHE S.A.'];

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.storage.disk' => 's3',
        'sources.storage.prefix' => 'sources',
        'sources.entries.ECB.allowed_hosts' => ['ecb-fixture.test'],
        'sources.entries.ECB.endpoints.institution_list_archive_template' => ARCHIVE,
        'sources.entries.GLEIF.allowed_hosts' => ['gleif-fixture.test'],
        'sources.entries.GLEIF.endpoints.bic_lei_page' => BIC_PAGE,
        'sources.entries.GLEIF.endpoints.legal_forms_page' => LEGAL_FORMS_PAGE,
        'banks.countries' => ['RO'],
        'banks.categories' => ['Credit Institution'],
        'banks.backfill.days_to_try' => 7,
    ]);

    // What the first import left: UniCredit, current since the list of 1 September 2025.
    Bank::factory()->create([
        'ecb_code' => UNICREDIT[0],
        'lei' => UNICREDIT[1],
        'bic' => 'BACXROBUXXX',
        'name' => 'UniCredit Bank',
        'legal_name' => UNICREDIT[2],
        'first_seen_on' => '2025-09-01',
        'last_seen_on' => '2025-09-01',
    ]);
});

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $banks  ECB code, LEI, name
 */
function archived(array $banks, bool $withLei = true): string
{
    $header = $withLei
        ? "RIAD_CODE\tLEI\tCOUNTRY_OF_REGISTRATION\tNAME\tBOX\tADDRESS\tPOSTAL\tCITY\tCATEGORY\tHEAD_COUNTRY_OF_REGISTRATION\tHEAD_NAME\tHEAD_RIAD_CODE\tHEAD_LEI\tREPORT"
        : "RIAD_CODE\tCOUNTRY_OF_REGISTRATION\tNAME\tBOX\tADDRESS\tPOSTAL\tCITY\tCATEGORY\tHEAD_COUNTRY_OF_REGISTRATION\tHEAD_NAME\tHEAD_RIAD_CODE\tREPORT";

    $rows = array_map(
        static fn (array $bank): string => $withLei
            ? "{$bank[0]}\t{$bank[1]}\tRO\t{$bank[2]}\t\t\t\tBUCURESTI\tCredit Institution\t\t\t\t\t"
            : "{$bank[0]}\tRO\t{$bank[2]}\t\t\t\tBUCURESTI\tCredit Institution\t\t\t\t",
        $banks,
    );

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

function archive(int $julyStatus = 200): void
{
    $day = static fn (string $yymmdd): string => str_replace('{date}', $yymmdd, ARCHIVE);

    Http::fake([
        $day('250701') => Http::response(archived([UNICREDIT, ALPHA], withLei: false), $julyStatus),
        $day('250804') => Http::response(archived([UNICREDIT, ALPHA, OLD_BANK])),
        'https://ecb-fixture.test/archive/*' => Http::response('<html>Not found</html>', 404),
        BIC_PAGE => Http::response('<a href="https://gleif-fixture.test/api/v2/bic-lei/fc357d54/download">LEI-BIC-20260828.zip</a>'),
        'https://gleif-fixture.test/api/v2/bic-lei/fc357d54/download' => Http::response(zipOf(
            'lei-bic-20260828.csv',
            // Today's GLEIF: Alpha's LEI points at UniCredit's code, the old bank keeps its own.
            "LEI,BIC\n" . UNICREDIT[1] . ",BACXROBUXXX\n" . ALPHA[1] . ",BACXROBUXXX\n" . OLD_BANK[1] . ",VECHROBUXXX\n",
        )),
        LEGAL_FORMS_PAGE => Http::response('<a href="https://gleif-fixture.test/files/2026-02-19-elf-code-list-v1.6.csv">CSV</a>'),
        'https://gleif-fixture.test/files/2026-02-19-elf-code-list-v1.6.csv' => Http::response(
            "\"ELF Code\",\"Country Code (ISO 3166-1)\",\"Entity Legal Form name Local name\",\"Entity Legal Form name Transliterated name (per ISO 01-140-10)\",\"Abbreviations Local language\",\"Abbreviations transliterated\"\n"
            . "\"ANDT\",\"RO\",\"Societate pe acţiuni\",\"\",\"SA\u{00A0}\",\"\"\n",
        ),
    ]);
}

it('adds the banks that closed before the first import, inactive and dated', function (): void {
    archive();

    $summary = app(BackfillBanks::class)('2025-07');

    $alpha = Bank::query()->where('ecb_code', ALPHA[0])->firstOrFail();
    $old = Bank::query()->where('ecb_code', OLD_BANK[0])->firstOrFail();

    expect($summary->snapshots)->toBe(2)
        ->and($summary->created)->toBe(2)
        ->and($summary->missingMonths)->toBe([])
        ->and([$alpha->name, $alpha->active, $alpha->first_seen_on->format('Y-m-d'), $alpha->last_seen_on->format('Y-m-d')])
        ->toBe(['Alpha Bank Romania', false, '2025-07-01', '2025-08-04'])
        // Its LEI now maps to UniCredit's code, which is not Alpha's to have.
        ->and($alpha->bic)->toBeNull()
        ->and([$old->active, $old->bic])->toBe([false, 'VECHROBUXXX']);
});

it('moves a current bank’s first appearance back, and changes nothing else about it', function (): void {
    archive();

    app(BackfillBanks::class)('2025-07');

    $unicredit = Bank::query()->where('ecb_code', UNICREDIT[0])->firstOrFail();

    expect([$unicredit->active, $unicredit->bic, $unicredit->first_seen_on->format('Y-m-d'), $unicredit->last_seen_on->format('Y-m-d')])
        ->toBe([true, 'BACXROBUXXX', '2025-07-01', '2025-09-01']);
});

it('adds nothing the second time', function (): void {
    archive();

    app(BackfillBanks::class)('2025-07');
    $again = app(BackfillBanks::class)('2025-07');

    expect($again->created)->toBe(0)
        ->and(Bank::query()->count())->toBe(3);
});

it('reports a month the archive has no list for', function (): void {
    archive();
    config(['banks.backfill.days_to_try' => 3]);

    // August's first list is on the 4th, past the three days tried.
    $summary = app(BackfillBanks::class)('2025-07');

    expect($summary->missingMonths)->toBe(['2025-08'])
        ->and($summary->snapshots)->toBe(1);
});

it('stops on a failure that is not a missing day', function (): void {
    archive(julyStatus: 503);

    expect(fn () => app(BackfillBanks::class)('2025-07'))->toThrow(UnexpectedStatusException::class);
    expect(Bank::query()->count())->toBe(1);
});

it('needs the current list first', function (): void {
    Bank::query()->delete();
    archive();

    expect(fn () => app(BackfillBanks::class)('2025-07'))->toThrow(BankSourceNotConfiguredException::class);
});
