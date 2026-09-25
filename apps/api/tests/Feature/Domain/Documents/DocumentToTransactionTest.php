<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\DocumentToTransactionTest;

use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Models\FinancialTransaction;
use Illuminate\Support\Facades\DB;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\TestCase;

/**
 * The bridge: a document becomes a transaction that keeps it as evidence.
 *
 * Before this, Wallet and Documents never met — every transaction was `MANUAL`, and a receipt sat
 * encrypted in the object store explaining nothing.
 *
 * Every document here is `READY` on purpose. A document the pipeline has not finished with cannot
 * become a transaction at all — see the state tests at the foot of this file — so a factory default
 * of `QUARANTINE` would be testing the refusal, not the bridge.
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

/**
 * A reading of this document that a person has accepted.
 *
 * Every test that expects a transaction to be created needs one: an import draws its figures from a
 * reading somebody agreed to, and without that the endpoint refuses — which is the point of the
 * state tests at the foot of this file.
 */
function acceptedReading(string $documentId): void
{
    reading($documentId, 'ACCEPTED');
}

/** One reading row, in the given state. Inserted rather than produced by the pipeline: what is
 * under test is the gate, and the gate cares only that a row exists with that status. */
function reading(string $documentId, string $status): void
{
    DB::table('document_extractions')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid7(),
        'document_id' => $documentId,
        'extractor_type' => 'EXTERNAL_AI',
        'extractor_version' => 'gateway@1',
        'schema_version' => '1',
        'result_ciphertext' => '{}',
        'confidence_decimal' => '0.9000',
        'status' => $status,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
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
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

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
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);
    $other = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);

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
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

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
    $documentB = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectB]);

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
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

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
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

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

    $documentB = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectB]);

    $this->actingAs($userA)
        ->deleteJson("/api/v1/subjects/{$subjectA}/documents/{$documentB->id}")
        ->assertNotFound();
});

it('refuses to turn a reading nobody has accepted into money', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    reading($document->id, 'PROPOSED');

    // The whole point of the review screen: a model proposed these figures and nobody has agreed to
    // them. Hiding the button is a courtesy; this is the boundary.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'AWAITING_ACCEPTANCE');

    expect(FinancialTransaction::query()->count())->toBe(0);
});

it('allows it once a person has accepted the reading', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertCreated();
});

it('refuses a second import from the same document', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

    $body = [
        'account_id' => $accountId,
        'amount_minor' => -1340,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-04',
        'description' => 'Kaufland',
    ];

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", $body)
        ->assertCreated();

    // A double-submitted form, a stale tab, a re-run of a script: the same receipt counted twice is
    // a wrong tax return, and nothing downstream would flag it as a duplicate.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", $body)
        ->assertStatus(409)
        ->assertJsonPath('reason', 'IMPORTED');

    expect(FinancialTransaction::query()->count())->toBe(1);
});

it('refuses a document the pipeline has not finished with', function (): void {
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
        ->assertStatus(409)
        ->assertJsonPath('reason', 'UNAVAILABLE');
});

it('refuses a document nothing could be read from', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);

    // `READY` with no reading at all: the model was asked and came back with nothing usable. This
    // used to be allowed on the reasoning that a person could type the figures by hand — which put
    // an import button, and a "to import" badge, on a document where nothing had been read.
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
        ])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'AWAITING_ACCEPTANCE');
});

it('files the transaction under the category the person chose', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => 27944800,
            'currency_code' => 'USD',
            'booked_at' => '2026-03-04',
            'description' => 'Dividende nete',
            'category' => 'DIVIDENDS',
        ])
        ->assertCreated();

    $transaction = FinancialTransaction::query()->sole();
    $split = DB::table('transaction_splits')->where('transaction_id', $transaction->id)->sole();

    // One split for the whole amount, and the transaction marked classified because of it — the
    // category is not a label on the side, it is what `transaction_splits` exists to hold.
    expect($split->category_code)->toBe('DIVIDENDS')
        ->and($split->amount_minor)->toBe(27944800)
        ->and($transaction->classification_status->value)->toBe('CLASSIFIED')
        // The currency is the one chosen, not the account's: a dividend paid in USD is a USD
        // transaction, whatever the source it is recorded against holds.
        ->and($transaction->currency_code)->toBe('USD');
});

it('refuses a category that is not one of ours', function (): void {
    [$user, $subjectId] = bridgeSubject();
    $accountId = bridgeAccount($this, $user, $subjectId);
    $document = Document::factory()->status(DocumentStatus::READY)->create(['tax_subject_id' => $subjectId]);
    acceptedReading($document->id);

    // `category_code` feeds the tax engine. A free-text code would reach `TaxFact` and mean nothing
    // to the rules that read it.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => $accountId,
            'amount_minor' => -1340,
            'currency_code' => 'RON',
            'booked_at' => '2026-03-04',
            'description' => 'Kaufland',
            'category' => 'SOMETHING_ELSE',
        ])
        ->assertStatus(422);
});
