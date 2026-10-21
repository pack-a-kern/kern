<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\DocumentExtractionReviewControllerTest;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Kern\Documents\Enums\ExtractionStatus;
use Kern\Documents\Enums\ExtractorType;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use RuntimeException;

/**
 * `GET .../documents/{document}/extractions`, `POST .../accept`, `POST .../correct` —
 * `BLUEPRINT.md` §14.6's human review workflow, the second of the three gaps
 * `docs/handoff/030-pwa-frontend.md`'s Key decision #2 found missing entirely.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/**
 * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
 *
 * @return array<int, array<string, mixed>>
 */
function dataRows(TestResponse $response): array
{
    $data = $response->json('data');

    if (! is_array($data)) {
        throw new RuntimeException('Expected a "data" array in the response.');
    }

    return array_map(static function (mixed $row): array {
        if (! is_array($row)) {
            throw new RuntimeException('Expected each "data" row to be an array.');
        }

        return $row;
    }, $data);
}

/** @return array{0: User, 1: TaxSubject} */
function memberAndSubject(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject];
}

it('lists proposed extractions for a document with decrypted proposed fields', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::EXTERNAL_DOCUMENT_AI,
        '1.0.0',
        'acme-ai',
        'acme-extractor',
        '1.0.0',
        ['invoice_number' => 'INV-001', 'total_amount' => '123.45'],
        '0.9500',
    );

    $response = $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions")
        ->assertOk();

    $rows = dataRows($response);
    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'id' => $extraction->id,
        'extractor_type' => 'EXTERNAL_DOCUMENT_AI',
        'status' => 'PROPOSED',
        'reviewed_by' => null,
        'proposed_fields' => ['invoice_number' => 'INV-001', 'total_amount' => '123.45'],
    ]);
    expect($response->json())->toHaveKeys(['data', 'path', 'per_page', 'next_cursor', 'prev_cursor']);
});

it('404s listing extractions for a document belonging to a different subject', function (): void {
    [$userA, $subjectA] = memberAndSubject();
    [, $subjectB] = memberAndSubject();
    $documentB = Document::factory()->create(['tax_subject_id' => $subjectB->id]);

    $this->actingAs($userA, 'web')
        ->getJson("/api/v1/subjects/{$subjectA->id}/documents/{$documentB->id}/extractions")
        ->assertNotFound();
});

it('accepts a proposed extraction, recording the reviewer and a timestamp', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::EXTERNAL_DOCUMENT_AI,
        '1.0.0',
        'acme-ai',
        'acme-extractor',
        '1.0.0',
        ['field' => 'value'],
        '0.9000',
    );

    $response = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/{$extraction->id}/accept")
        ->assertOk();

    expect($response->json('status'))->toBe('ACCEPTED')
        ->and($response->json('reviewed_by'))->toBe($user->id)
        ->and($response->json('reviewed_at'))->not->toBeNull();

    $fresh = $extraction->fresh();
    expect($fresh)->not->toBeNull();
    assert($fresh !== null);
    expect($fresh->status)->toBe(ExtractionStatus::ACCEPTED)
        ->and($fresh->reviewed_by)->toBe($user->id);

    // The original proposed content is untouched by acceptance.
    $raw = DB::table('document_extractions')->where('id', $extraction->id)->first();
    expect($raw)->not->toBeNull();
});

it('refuses to accept an extraction twice', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::EXTERNAL_DOCUMENT_AI,
        '1.0.0',
        null,
        null,
        '1.0.0',
        ['field' => 'value'],
        '0.9000',
    );

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/{$extraction->id}/accept")
        ->assertOk();

    $response = $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/{$extraction->id}/accept")
        ->assertStatus(409);

    expect($response->json('reason'))->toBe('ALREADY_REVIEWED');
});

it('corrects a proposed extraction: the original row is preserved, a new accepted row holds the correction', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $original = DocumentExtraction::propose(
        $document,
        ExtractorType::EXTERNAL_DOCUMENT_AI,
        '1.0.0',
        'acme-ai',
        'acme-extractor',
        '1.0.0',
        ['invoice_number' => 'WRONG-000'],
        '0.4000',
    );

    $response = $this->actingAs($user, 'web')
        ->postJson(
            "/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/{$original->id}/correct",
            ['fields' => ['invoice_number' => 'INV-999']],
        )
        ->assertCreated();

    $correctedId = jsonString($response, 'id');
    expect($correctedId)->not->toBe($original->id)
        ->and($response->json('status'))->toBe('ACCEPTED')
        ->and($response->json('reviewed_by'))->toBe($user->id)
        ->and($response->json('supersedes_extraction_id'))->toBe($original->id)
        ->and($response->json('proposed_fields'))->toBe(['invoice_number' => 'INV-999']);

    // The original proposal is preserved, byte for byte, never overwritten in place. Reloaded
    // fresh from the database, so decrypting it needs the subject id supplied explicitly
    // (Kern\Documents\Models\DocumentExtraction::withEncryptionSubjectId()'s own docblock).
    $originalFresh = $original->fresh()?->withEncryptionSubjectId($subject->id);
    expect($originalFresh)->not->toBeNull();
    assert($originalFresh !== null);
    expect($originalFresh->status)->toBe(ExtractionStatus::REJECTED)
        ->and($originalFresh->reviewed_by)->toBe($user->id)
        ->and(json_decode($originalFresh->result_ciphertext, true))->toBe(['invoice_number' => 'WRONG-000']);

    // The correction lives in its own row.
    $corrected = DocumentExtraction::query()->find($correctedId)?->withEncryptionSubjectId($subject->id);
    expect($corrected)->not->toBeNull();
    assert($corrected !== null);
    expect($corrected->extractor_type)->toBe(ExtractorType::HUMAN_CORRECTION)
        ->and($corrected->status)->toBe(ExtractionStatus::ACCEPTED)
        ->and(json_decode($corrected->result_ciphertext, true))->toBe(['invoice_number' => 'INV-999']);

    expect(DocumentExtraction::query()->where('document_id', $document->id)->count())->toBe(2);
});

it('refuses to correct an already-reviewed extraction', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::EXTERNAL_DOCUMENT_AI,
        '1.0.0',
        null,
        null,
        '1.0.0',
        ['field' => 'value'],
        '0.9000',
    );
    $extraction->review($user->id, ExtractionStatus::ACCEPTED);

    $response = $this->actingAs($user, 'web')
        ->postJson(
            "/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/{$extraction->id}/correct",
            ['fields' => ['field' => 'new-value']],
        )
        ->assertStatus(409);

    expect($response->json('reason'))->toBe('ALREADY_REVIEWED');
});

it('requires a non-empty fields object to correct', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $extraction = DocumentExtraction::propose(
        $document,
        ExtractorType::EXTERNAL_DOCUMENT_AI,
        '1.0.0',
        null,
        null,
        '1.0.0',
        ['field' => 'value'],
        '0.9000',
    );

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/{$extraction->id}/correct", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['fields']);
});

it('404s accepting an extraction that belongs to a different subject\'s document, even addressed through the right document', function (): void {
    [$userA, $subjectA] = memberAndSubject();
    [, $subjectB] = memberAndSubject();
    $documentA = Document::factory()->create(['tax_subject_id' => $subjectA->id]);
    $documentB = Document::factory()->create(['tax_subject_id' => $subjectB->id]);
    $extractionB = DocumentExtraction::propose(
        $documentB,
        ExtractorType::EXTERNAL_DOCUMENT_AI,
        '1.0.0',
        null,
        null,
        '1.0.0',
        ['field' => 'value'],
        '0.9000',
    );

    // subjectA/documentA is a real, owned document — but the extraction id belongs to
    // subjectB's document, not documentA's.
    $this->actingAs($userA, 'web')
        ->postJson("/api/v1/subjects/{$subjectA->id}/documents/{$documentA->id}/extractions/{$extractionB->id}/accept")
        ->assertNotFound();
});

it('refuses access for a user with no membership on the subject', function (): void {
    [, $subject] = memberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);
    $outsider = User::factory()->create();

    $this->actingAs($outsider, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions")
        ->assertStatus(403);
});
