<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\RetryDocumentExtractionControllerTest;

use Illuminate\Support\Facades\Queue;
use Kern\Documents\Enums\AiExtractionOutcome;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\ExtractorType;
use Kern\Documents\Jobs\ProcessScannedDocument;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentExtraction;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * `POST /api/v1/subjects/{subject}/documents/{document}/extractions/retry` — the remedy the review
 * screen offers next to a recorded extraction failure.
 *
 * It exists because there was no supported way back from one: `ProcessScannedDocument` sets
 * `$tries = 1` and catches its own failure, so nothing ever reaches `failed_jobs` and `queue:retry`
 * has nothing to work with.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );

    Queue::fake();
});

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

it('queues the reading again for a READY document with a recorded failure', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
        'ai_extraction_outcome' => AiExtractionOutcome::FAILED_HTTP_STATUS,
    ]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/retry")
        ->assertStatus(202);

    Queue::assertPushed(
        ProcessScannedDocument::class,
        static fn (ProcessScannedDocument $job): bool => $job->documentId === $document->id,
    );

    // Claimed up front, so the screen can say "reading" from the moment the request is accepted
    // rather than from whenever a worker gets to the job.
    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::PROCESSING);
});

it('queues exactly one reading when the button is pressed repeatedly', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
        'ai_extraction_outcome' => AiExtractionOutcome::FAILED_HTTP_STATUS,
    ]);

    $url = "/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/retry";

    $this->actingAs($user, 'web')->postJson($url)->assertStatus(202);

    // The second and third presses land before any worker has touched the job — which is exactly the
    // window that previously produced three concurrent jobs, three proposals, and three separate
    // sends of this document to an external provider.
    $this->actingAs($user, 'web')->postJson($url)->assertStatus(409)->assertJson([
        'code' => 'DOCUMENT_NOT_READY',
        'status' => 'PROCESSING',
    ]);
    $this->actingAs($user, 'web')->postJson($url)->assertStatus(409);

    Queue::assertPushed(ProcessScannedDocument::class, 1);
});

it('refuses a document that is still being processed', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::QUARANTINE,
    ]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/retry")
        ->assertStatus(409)
        ->assertJson(['code' => 'DOCUMENT_NOT_READY', 'status' => 'QUARANTINE']);

    Queue::assertNothingPushed();
});

it('refuses a document that already has a proposal, rather than producing a second one to choose between', function (): void {
    [$user, $subject] = memberAndSubject();
    $document = Document::factory()->create([
        'tax_subject_id' => $subject->id,
        'status' => DocumentStatus::READY,
    ]);

    DocumentExtraction::propose(
        document: $document,
        extractorType: ExtractorType::EXTERNAL_DOCUMENT_AI,
        extractorVersion: '2026.09.0',
        modelProvider: 'acme-ai',
        modelName: 'acme-extractor-v3',
        schemaVersion: '1.0.0',
        result: ['vendor' => 'ACME SRL'],
        confidenceDecimal: '0.9100',
    );

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/extractions/retry")
        ->assertStatus(409)
        ->assertJson(['code' => 'EXTRACTION_ALREADY_EXISTS']);

    Queue::assertNothingPushed();
});

it('does not let one subject re-read another subject\'s document', function (): void {
    [$user, $subject] = memberAndSubject();
    $otherDocument = Document::factory()->create(['status' => DocumentStatus::READY]);

    // Tenant-scoped: not found, never a 403 that would confirm the document exists.
    $this->actingAs($user, 'web')
        ->postJson("/api/v1/subjects/{$subject->id}/documents/{$otherDocument->id}/extractions/retry")
        ->assertStatus(404);

    Queue::assertNothingPushed();
});
