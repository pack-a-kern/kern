<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Fx\FxRateImporterTest;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Kern\Legislation\Database\Factories\OfficialSourceFactory;
use Kern\Legislation\Database\Factories\SourceDocumentVersionFactory;
use Kern\TaxRomania\Fx\DataTransferObjects\ParsedFxRate;
use Kern\TaxRomania\Fx\Enums\FxRateImportOutcome;
use Kern\TaxRomania\Fx\Models\FxRate;
use Kern\TaxRomania\Fx\Models\FxRateAnomaly;
use Kern\TaxRomania\Fx\Services\FxRateImporter;
use Kern\TaxRomania\Fx\ValueObjects\DecimalRate;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('creates a new ext_fx_rates row when none exists for that source/date/currency', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();

    $outcome = (new FxRateImporter())->import(
        $source->id,
        new DateTimeImmutable('2026-01-15'),
        new ParsedFxRate('EUR', 1, DecimalRate::fromString('4.976400')),
        $version->id,
    );

    expect($outcome)->toBe(FxRateImportOutcome::CREATED)
        ->and(FxRate::query()->count())->toBe(1);

    $row = FxRate::query()->firstOrFail();
    expect($row->currency_code)->toBe('EUR')
        ->and($row->multiplier)->toBe(1)
        ->and((string) $row->rate_decimal)->toBe('4.976400');
});

it('is idempotent: importing the identical value twice leaves exactly one row and no anomaly', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();
    $rate = new ParsedFxRate('EUR', 1, DecimalRate::fromString('4.976400'));
    $importer = new FxRateImporter();

    $first = $importer->import($source->id, new DateTimeImmutable('2026-01-15'), $rate, $version->id);
    $second = $importer->import($source->id, new DateTimeImmutable('2026-01-15'), $rate, $version->id);

    expect($first)->toBe(FxRateImportOutcome::CREATED)
        ->and($second)->toBe(FxRateImportOutcome::UNCHANGED)
        ->and(FxRate::query()->count())->toBe(1)
        ->and(FxRateAnomaly::query()->count())->toBe(0);
});

it('treats a value written with more trailing zeros as unchanged, not an anomaly', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();
    $importer = new FxRateImporter();

    $importer->import($source->id, new DateTimeImmutable('2026-01-15'), new ParsedFxRate('EUR', 1, DecimalRate::fromString('4.9764')), $version->id);
    $outcome = $importer->import($source->id, new DateTimeImmutable('2026-01-15'), new ParsedFxRate('EUR', 1, DecimalRate::fromString('4.976400')), $version->id);

    expect($outcome)->toBe(FxRateImportOutcome::UNCHANGED)
        ->and(FxRateAnomaly::query()->count())->toBe(0);
});

it('records an anomaly instead of overwriting when a historical value changes, leaving the original row untouched', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $originalVersion = SourceDocumentVersionFactory::new()->createOne();
    $conflictingVersion = SourceDocumentVersionFactory::new()->createOne();
    $importer = new FxRateImporter();

    $importer->import(
        $source->id,
        new DateTimeImmutable('2026-01-15'),
        new ParsedFxRate('EUR', 1, DecimalRate::fromString('4.976400')),
        $originalVersion->id,
    );

    $outcome = $importer->import(
        $source->id,
        new DateTimeImmutable('2026-01-15'),
        new ParsedFxRate('EUR', 1, DecimalRate::fromString('5.100000')),
        $conflictingVersion->id,
    );

    expect($outcome)->toBe(FxRateImportOutcome::ANOMALY)
        ->and(FxRate::query()->count())->toBe(1)
        ->and(FxRateAnomaly::query()->count())->toBe(1);

    // The original ext_fx_rates row must be byte-for-byte untouched — never a silent overwrite.
    $row = FxRate::query()->firstOrFail();
    expect((string) $row->rate_decimal)->toBe('4.976400')
        ->and($row->source_document_version_id)->toBe($originalVersion->id);

    $anomaly = FxRateAnomaly::query()->firstOrFail();
    expect((string) $anomaly->existing_rate_decimal)->toBe('4.976400')
        ->and((string) $anomaly->incoming_rate_decimal)->toBe('5.100000')
        ->and($anomaly->incoming_source_document_version_id)->toBe($conflictingVersion->id);
});

it('records an anomaly when only the multiplier changes, even if the raw rate number is identical', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version1 = SourceDocumentVersionFactory::new()->createOne();
    $version2 = SourceDocumentVersionFactory::new()->createOne();
    $importer = new FxRateImporter();

    $importer->import($source->id, new DateTimeImmutable('2026-01-15'), new ParsedFxRate('HUF', 100, DecimalRate::fromString('1279.99')), $version1->id);
    $outcome = $importer->import($source->id, new DateTimeImmutable('2026-01-15'), new ParsedFxRate('HUF', 1, DecimalRate::fromString('1279.99')), $version2->id);

    expect($outcome)->toBe(FxRateImportOutcome::ANOMALY)
        ->and(FxRateAnomaly::query()->count())->toBe(1);
});

it('scopes rows independently per currency and per date', function (): void {
    $source = OfficialSourceFactory::new()->createOne();
    $version = SourceDocumentVersionFactory::new()->createOne();
    $importer = new FxRateImporter();

    $importer->import($source->id, new DateTimeImmutable('2026-01-15'), new ParsedFxRate('EUR', 1, DecimalRate::fromString('4.9764')), $version->id);
    $importer->import($source->id, new DateTimeImmutable('2026-01-15'), new ParsedFxRate('USD', 1, DecimalRate::fromString('4.5821')), $version->id);
    $importer->import($source->id, new DateTimeImmutable('2026-01-16'), new ParsedFxRate('EUR', 1, DecimalRate::fromString('4.9778')), $version->id);

    expect(FxRate::query()->count())->toBe(3)
        ->and(FxRateAnomaly::query()->count())->toBe(0);
});
