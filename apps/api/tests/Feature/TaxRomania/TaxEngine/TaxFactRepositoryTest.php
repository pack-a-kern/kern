<?php

declare(strict_types=1);

use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\TaxEngine\Models\TaxFact;
use Kern\TaxRomania\TaxEngine\Repositories\TaxFactRepository;

beforeEach(function (): void {
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());
    $provider = new ConfigBlindIndexKeyProvider(json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR), 1);
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
});

it('builds snapshots only for the requested subject, even without a bound TenantContext', function (): void {
    $subjectA = TaxSubject::factory()->create();
    $subjectB = TaxSubject::factory()->create();

    $factA = TaxFact::factory()->create(['tax_subject_id' => $subjectA->id, 'ron_amount_minor' => 111100]);
    TaxFact::factory()->create(['tax_subject_id' => $subjectB->id]);

    $snapshots = (new TaxFactRepository())->snapshotsFor($subjectA->id, [$factA->id]);

    expect($snapshots)->toHaveCount(1)
        ->and($snapshots[0]->id)->toBe($factA->id)
        ->and($snapshots[0]->grossAmountRon->minorUnits)->toBe(111100)
        ->and($snapshots[0]->grossAmountRon->currencyCode)->toBe('RON');
});

it('never returns a fact belonging to a different subject even if its id is requested', function (): void {
    $subjectA = TaxSubject::factory()->create();
    $subjectB = TaxSubject::factory()->create();

    $factB = TaxFact::factory()->create(['tax_subject_id' => $subjectB->id]);

    $snapshots = (new TaxFactRepository())->snapshotsFor($subjectA->id, [$factB->id]);

    expect($snapshots)->toBe([]);
});

it('maps every §10.3 date and the deductible/withheld RON amounts', function (): void {
    $subject = TaxSubject::factory()->create();

    $fact = TaxFact::factory()->create([
        'tax_subject_id' => $subject->id,
        'recognition_date' => '2026-03-01',
        'distributed_at' => '2026-02-15',
        'deductible_amount_ron_minor' => 500,
        'withheld_tax_ron_minor' => 250,
    ]);

    $snapshot = (new TaxFactRepository())->snapshotsFor($subject->id, [$fact->id])[0];

    expect($snapshot->recognitionDate->format('Y-m-d'))->toBe('2026-03-01')
        ->and($snapshot->distributedAt?->format('Y-m-d'))->toBe('2026-02-15')
        ->and($snapshot->cashDate)->toBeNull()
        ->and($snapshot->deductibleAmountRon?->minorUnits)->toBe(500)
        ->and($snapshot->withheldTaxRon?->minorUnits)->toBe(250);
});
