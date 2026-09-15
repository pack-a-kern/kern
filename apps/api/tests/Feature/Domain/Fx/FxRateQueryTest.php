<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Fx\FxRateQueryTest;

use App\Domain\Fx\Exceptions\FxRateNotFoundException;
use App\Domain\Fx\Models\FxRate;
use App\Domain\Fx\Services\FxRateQuery;
use Database\Factories\OfficialSourceFactory;
use Database\Factories\SourceDocumentVersionFactory;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('returns the reproducible per-unit rate for an exact source/currency/date match', function (): void {
    $source = OfficialSourceFactory::new()->createOne(['code' => 'BNR']);
    $version = SourceDocumentVersionFactory::new()->createOne();

    FxRate::query()->create([
        'official_source_id' => $source->id,
        'rate_date' => '2026-01-15',
        'currency_code' => 'EUR',
        'multiplier' => 1,
        'rate_decimal' => '4.976400',
        'source_document_version_id' => $version->id,
    ]);

    $query = app(FxRateQuery::class);
    $rate = $query->rate('EUR', new DateTimeImmutable('2026-01-15'));

    expect($rate->toString())->toBe('4.9764000000');
});

it('divides the multiplier out of the per-unit rate', function (): void {
    $source = OfficialSourceFactory::new()->createOne(['code' => 'BNR']);
    $version = SourceDocumentVersionFactory::new()->createOne();

    FxRate::query()->create([
        'official_source_id' => $source->id,
        'rate_date' => '2026-01-15',
        'currency_code' => 'HUF',
        'multiplier' => 100,
        'rate_decimal' => '1279.990000',
        'source_document_version_id' => $version->id,
    ]);

    $query = app(FxRateQuery::class);
    $rate = $query->rate('HUF', new DateTimeImmutable('2026-01-15'));

    expect($rate->toString())->toBe('12.7999000000');
});

it('returns the raw document hash, path, and fetch time as provenance alongside the rate', function (): void {
    $source = OfficialSourceFactory::new()->createOne(['code' => 'BNR']);
    $version = SourceDocumentVersionFactory::new()->createOne([
        'content_sha256' => str_repeat('a', 64),
        'raw_object_path' => 'sources/bnr/deadbeef',
    ]);

    FxRate::query()->create([
        'official_source_id' => $source->id,
        'rate_date' => '2026-01-15',
        'currency_code' => 'EUR',
        'multiplier' => 1,
        'rate_decimal' => '4.976400',
        'source_document_version_id' => $version->id,
    ]);

    $query = app(FxRateQuery::class);
    $withProvenance = $query->rateWithProvenance('EUR', new DateTimeImmutable('2026-01-15'));

    expect($withProvenance->contentSha256)->toBe(str_repeat('a', 64))
        ->and($withProvenance->rawObjectPath)->toBe('sources/bnr/deadbeef')
        ->and($withProvenance->sourceDocumentVersionId)->toBe($version->id)
        ->and($withProvenance->fxSourceCode)->toBe('BNR')
        ->and($withProvenance->multiplier)->toBe(1)
        ->and($withProvenance->publishedRateDecimal->toString())->toBe('4.976400');
});

it('throws rather than interpolating when no exact rate exists for that date', function (): void {
    OfficialSourceFactory::new()->createOne(['code' => 'BNR']);

    $query = app(FxRateQuery::class);
    $query->rate('EUR', new DateTimeImmutable('2026-01-17'));
})->throws(FxRateNotFoundException::class);

it('throws when the fx source itself does not exist yet', function (): void {
    $query = app(FxRateQuery::class);
    $query->rate('EUR', new DateTimeImmutable('2026-01-17'));
})->throws(FxRateNotFoundException::class);
