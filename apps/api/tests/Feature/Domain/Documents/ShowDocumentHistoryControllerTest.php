<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\ShowDocumentHistoryControllerTest;

use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use Illuminate\Support\Carbon;
use Kern\Documents\Models\Document;
use Kern\Documents\Models\DocumentScan;
use Kern\Documents\Scanning\ScanOutcome;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * `GET /api/v1/subjects/{subject}/documents/{document}/history` — the scan records and audit-chain
 * entries for one document, without the administrator permission the cross-tenant audit screens
 * require.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/** @return array{0: User, 1: TaxSubject} */
function historyMemberAndSubject(): array
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

it('reports which engine and definitions cleared the document', function (): void {
    [$user, $subject] = historyMemberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    DocumentScan::create([
        'document_id' => $document->id,
        'outcome' => ScanOutcome::CLEAN,
        'scanner' => 'clamav',
        'scanner_version' => '1.5.4',
        'definitions_version' => '28118',
        'duration_ms' => 5,
        'scanned_at' => Carbon::parse('2026-09-09T20:26:18Z'),
    ]);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/history")
        ->assertOk()
        ->assertJsonPath('scans.0.outcome', 'CLEAN')
        ->assertJsonPath('scans.0.scanner', 'clamav')
        ->assertJsonPath('scans.0.scanner_version', '1.5.4')
        ->assertJsonPath('scans.0.definitions_version', '28118');
});

it('reports an unscanned document as having no scans rather than as clean', function (): void {
    [$user, $subject] = historyMemberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    // The distinction the whole table exists for: "nothing scanned this" must never render as a
    // passing verdict.
    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/history")
        ->assertOk()
        ->assertJsonCount(0, 'scans');
});

it('lists scans oldest first, so a re-scan reads as a sequence', function (): void {
    [$user, $subject] = historyMemberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    foreach ([['28118', '2026-09-09T20:00:00Z'], ['28200', '2026-09-20T09:00:00Z']] as [$definitions, $at]) {
        DocumentScan::create([
            'document_id' => $document->id,
            'outcome' => ScanOutcome::CLEAN,
            'scanner' => 'clamav',
            'scanner_version' => '1.5.4',
            'definitions_version' => $definitions,
            'duration_ms' => 5,
            'scanned_at' => Carbon::parse($at),
        ]);
    }

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/history")
        ->assertOk()
        ->assertJsonPath('scans.0.definitions_version', '28118')
        ->assertJsonPath('scans.1.definitions_version', '28200');
});

it('refuses the history of a document belonging to another subject', function (): void {
    [$userA, $subjectA] = historyMemberAndSubject();
    [, $subjectB] = historyMemberAndSubject();

    $documentB = Document::factory()->create(['tax_subject_id' => $subjectB->id]);

    $this->actingAs($userA, 'web')
        ->getJson("/api/v1/subjects/{$subjectA->id}/documents/{$documentB->id}/history")
        ->assertNotFound();
});

it('refuses a user with no membership on the subject', function (): void {
    [, $subject] = historyMemberAndSubject();
    $document = Document::factory()->create(['tax_subject_id' => $subject->id]);

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson("/api/v1/subjects/{$subject->id}/documents/{$document->id}/history")
        ->assertStatus(403);
});
