<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\DocumentToTransactionTest;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Support\KeyManagement\SubjectDekService;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\TestCase;

/**
 * The bridge: a document becomes a transaction that keeps it as evidence.
 *
 * Before this, Wallet and Documents never met — every transaction was `MANUAL`, and a receipt sat
 * encrypted in the object store explaining nothing.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/** @return array{0: User, 1: string} */
function bridgeSubject(): array
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

    return [$user, $subject->id];
}

function bridgeAccount(TestCase $case, User $user, string $subjectId): string
{
    $id = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont curent',
        'kind' => 'GENERAL',
        'currency_code' => 'RON',
    ])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
}

it('creates a transaction that names the document it came from', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->create(['tax_subject_id' => $subjectId]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            // Negative: a receipt is money going out. The sign *is* the direction — a separate
            // field would let the two disagree.
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertCreated();

    $transaction = FinancialTransaction::query()->sole();

    expect($transaction->document_id)->toBe($document->id)
        // Not MANUAL: "someone typed this" and "this came from a document still in the object
        // store, with its hash and its scan record" are different provenance.
        ->and($transaction->source->value)->toBe('DOCUMENT')
        ->and($transaction->amount_minor)->toBe(-1340);
});

it('refuses an extraction id belonging to a different document', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->create(['tax_subject_id' => $subjectId]);
    $other = Document::factory()->create(['tax_subject_id' => $subjectId]);

    $foreignExtraction = (string) \Illuminate\Support\Str::uuid7();
    DB::table('document_extractions')->insert([
        'id' => $foreignExtraction,
        'document_id' => $other->id,
        'extractor_type' => 'PATTERN_PARSER',
        'extractor_version' => 'receipt.ro.generic@1.0.0',
        'schema_version' => '1',
        'result_ciphertext' => '{}',
        'confidence_decimal' => '0.9000',
        'status' => 'PROPOSED',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
            'document_extraction_id' => $foreignExtraction,
        ])
        ->assertCreated();

    // Attaching provenance that points at a reading of something else is worse than none at all,
    // so the mismatched id is dropped rather than stored.
    expect(FinancialTransaction::query()->sole()->document_extraction_id)->toBeNull();
});

it('keeps the transaction when the document it came from is deleted', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->create(['tax_subject_id' => $subjectId]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertCreated();

    // `nullOnDelete`, never cascade: the figure was real, and losing its evidence is a loss of
    // provenance — not a reason to silently change someone's income.
    DB::table('documents')->where('id', $document->id)->delete();

    $transaction = FinancialTransaction::query()->sole();

    expect($transaction->exists)->toBeTrue()
        ->and($transaction->document_id)->toBeNull()
        ->and($transaction->amount_minor)->toBe(-1340);
});

it('refuses to use another subject document as evidence', function (): void {
    [$userA, $subjectA] = bridgeSubject();
    [, $subjectB] = bridgeSubject();

    $accountA = bridgeAccount($this, $userA, $subjectA);
    $documentB = Document::factory()->create(['tax_subject_id' => $subjectB]);

    $this->actingAs($userA)
        ->postJson("/api/v1/subjects/{$subjectA}/documents/{$documentB->id}/transaction", [
            'account_id' => $accountA,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertNotFound();
});

it('refuses a zero amount, as every other way of recording a transaction does', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->create(['tax_subject_id' => $subjectId]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => 0,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertStatus(422);
});

it('deletes a document without touching the transaction built from it', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->create(['tax_subject_id' => $subjectId]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->deleteJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}")
        ->assertStatus(204);

    $transaction = FinancialTransaction::query()->sole();

    // The figure was real. Losing its evidence is a loss of provenance, not a reason to change
    // someone's income — so the row stays and only the link goes.
    expect($transaction->amount_minor)->toBe(-1340)
        ->and($transaction->document_id)->toBeNull();

    // Every entry the document produced stays, plus one recording that it was removed. That is what
    // an append-only chain is for.
    expect(DB::table('audit_events')->where('resource_id', $document->id)->where('event_type', 'DocumentDeleted')->count())
        ->toBe(1);
});

it('refuses to delete another subject document', function (): void {
    [$userA, $subjectA] = bridgeSubject();
    [, $subjectB] = bridgeSubject();

    $documentB = Document::factory()->create(['tax_subject_id' => $subjectB]);

    $this->actingAs($userA)
        ->deleteJson("/api/v1/subjects/{$subjectA}/documents/{$documentB->id}")
        ->assertNotFound();
});
