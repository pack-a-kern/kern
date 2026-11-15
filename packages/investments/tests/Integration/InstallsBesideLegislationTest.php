<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Integration\InstallsBesideLegislationTest;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
use Kern\Legislation\Fetching\Exceptions\HostNotAllowedException;

/** @param array<string, mixed> $overrides */
function instrument(array $overrides = []): FinancialInstrument
{
    return FinancialInstrument::query()->create([
        'kind' => InstrumentKind::GOVERNMENT_BOND,
        'programme' => Programme::FIDELIS,
        'isin' => 'ROEXAMPLE001',
        'symbol' => 'R2899A',
        'name' => 'Example issue',
        'issuer_name' => 'Example issuer',
        'currency' => 'RON',
        'face_value' => '100',
        'rate' => '6.95',
        'market' => Market::BVB,
        'status' => InstrumentStatus::ACTIVE,
        ...$overrides,
    ]);
}

it('adds its source to the registry beside legislation’s own, and fetches it like any other', function (): void {
    $registry = app(OfficialSourceRegistry::class);
    Http::fake(['https://www.bvb.ro/*' => Http::response('<html></html>')]);

    app(AllowlistedHttpFetcher::class)->fetch('BVB', app(InvestmentsConfig::class)->bvb->sharesUrl);

    expect($registry->find('LEGISLATIE')->code)->toBe('LEGISLATIE')
        ->and($registry->find('BVB')->userAgent)->toBe('Mozilla/5.0 (Test) Chrome/128.0.0.0');
    Http::assertSent(fn ($request): bool => $request->header('User-Agent')[0] === 'Mozilla/5.0 (Test) Chrome/128.0.0.0');
});

it('refuses a host its source does not allow', function (): void {
    Http::fake();

    app(AllowlistedHttpFetcher::class)->fetch('BVB', 'https://example.com/shares');
})->throws(HostNotAllowedException::class);

it('keeps one instrument per ISIN, and any number with none', function (): void {
    instrument();
    instrument(['programme' => Programme::TEZAUR, 'isin' => null, 'symbol' => null, 'market' => null, 'issue_code' => '0001']);
    instrument(['programme' => Programme::TEZAUR, 'isin' => null, 'symbol' => null, 'market' => null, 'issue_code' => '0002']);

    expect(fn () => instrument(['symbol' => 'R2899B']))->toThrow(QueryException::class);
});

it('keeps one Tezaur issue per code, and one listed symbol per market', function (): void {
    instrument(['programme' => Programme::TEZAUR, 'isin' => null, 'symbol' => null, 'market' => null, 'issue_code' => '0001']);
    instrument(['isin' => 'ROEXAMPLE002', 'symbol' => 'R2899B']);

    expect(fn () => instrument(['programme' => Programme::TEZAUR, 'isin' => null, 'symbol' => null, 'market' => null, 'issue_code' => '0001']))
        ->toThrow(QueryException::class)
        ->and(fn () => instrument(['isin' => 'ROEXAMPLE003', 'symbol' => 'R2899B']))->toThrow(QueryException::class);
});

it('writes a decimal only as a plain decimal', function (string $rate): void {
    expect(fn () => instrument(['rate' => $rate]))->toThrow(InvalidArgumentException::class, 'ext_financial_instruments.rate');
})->with(['an exponent' => ['6.95E-2'], 'a locale comma' => ['6,95'], 'a thousands separator' => ['1.000.000'], 'text' => ['six']]);

it('indexes the name for the search', function (): void {
    expect(DB::selectOne("SELECT 1 AS found FROM pg_indexes WHERE indexname = 'ext_financial_instruments_name_trgm'"))->not->toBeNull();
});

/**
 * ADR 0027 — the bonds feature rests on the Ministry's orders being in the library, so this package
 * declares two feature requirements naming the same feature, one per programme. The readiness
 * roll-up ANDs every requirement that gates a feature, so `investments.bonds` is available only once
 * both the Fidelis and the Tezaur watch have read an order (the behaviour itself is covered in the
 * legislation suite's two-requirement roll-up; here the declaration is pinned).
 */
it('declares the bonds feature against both the Fidelis and the Tezaur orders', function (): void {
    $declared = config('legislation.requirements.declared');

    expect($declared)->toHaveKey('investments.bonds-fidelis')
        ->and($declared)->toHaveKey('investments.bonds-tezaur')
        ->and($declared['investments.bonds-fidelis']['tier'])->toBe('feature')
        ->and($declared['investments.bonds-fidelis']['feature'])->toBe('investments.bonds')
        ->and($declared['investments.bonds-fidelis']['declared_by'])->toBe('kern/investments')
        ->and($declared['investments.bonds-fidelis']['needs']['watch'])->toBe('investments.fidelis')
        ->and($declared['investments.bonds-tezaur']['feature'])->toBe('investments.bonds')
        ->and($declared['investments.bonds-tezaur']['needs']['watch'])->toBe('investments.tezaur');
});
