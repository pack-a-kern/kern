<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\DocumentExtractionAutoConfirmTest;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kern\Documents\Enums\ExtractionStatus;
use Kern\Documents\Enums\ExtractorType;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;

/**
 * `BLUEPRINT.md` §14.6 — "AI-extracted values that create tax facts are PROPOSED; never CONFIRMED
 * automat în v1." `docs/handoff/022-document-pipeline.md`'s mandatory test: an extraction cannot
 * be auto-confirmed, at the model level, independent of any job or controller.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

it('always proposes a new extraction as PROPOSED, regardless of confidence', function (): void {
    $document = Document::factory()->create();

    $extraction = DocumentExtraction::propose(
        document: $document,
        extractorType: ExtractorType::EXTERNAL_DOCUMENT_AI,
        extractorVersion: '1.0.0',
        modelProvider: 'acme-ai',
        modelName: 'acme-extractor',
        schemaVersion: '1.0.0',
        result: ['field' => 'value'],
        confidenceDecimal: '0.9999',
    );

    expect($extraction->status)->toBe(ExtractionStatus::PROPOSED);
});

it('cannot be moved out of PROPOSED by mass assignment', function (): void {
    $document = Document::factory()->create();
    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::NATIVE_PDF_TEXT,
        '1.0.0',
        null,
        null,
        '1.0.0',
        ['x' => 1],
        '0.5000',
    );

    $extraction->fill(['status' => ExtractionStatus::ACCEPTED])->save();

    $fresh = $extraction->fresh();
    expect($fresh)->not->toBeNull();
    assert($fresh !== null);
    expect($fresh->status)->toBe(ExtractionStatus::PROPOSED);
});

it('the only status-changing path requires an explicit human reviewer and a real decision', function (): void {
    $reviewer = User::factory()->create();
    $document = Document::factory()->create();
    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::NATIVE_PDF_TEXT,
        '1.0.0',
        null,
        null,
        '1.0.0',
        ['x' => 1],
        '0.5000',
    );

    expect(fn () => $extraction->review('', ExtractionStatus::ACCEPTED))->toThrow(InvalidArgumentException::class);
    expect(fn () => $extraction->review($reviewer->id, ExtractionStatus::PROPOSED))->toThrow(InvalidArgumentException::class);

    $extraction->review($reviewer->id, ExtractionStatus::ACCEPTED);
    $fresh = $extraction->fresh();
    expect($fresh)->not->toBeNull();
    assert($fresh !== null);
    expect($fresh->status)->toBe(ExtractionStatus::ACCEPTED)
        ->and($fresh->reviewed_by)->toBe($reviewer->id);
});

it('encrypts the extraction result — the raw database row never contains the plaintext result', function (): void {
    $document = Document::factory()->create();
    $marker = 'RECOGNISABLE-EXTRACTION-RESULT-MARKER';

    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::NATIVE_PDF_TEXT,
        '1.0.0',
        null,
        null,
        '1.0.0',
        ['note' => $marker],
        '0.5000',
    );

    $raw = DB::table('document_extractions')->where('id', $extraction->id)->first();
    expect($raw)->not->toBeNull();
    assert($raw !== null);

    expect($raw->result_ciphertext)->not->toContain($marker);
});
