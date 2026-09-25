<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\DuplicateTransactionsTest;

use Illuminate\Support\Facades\DB;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Models\Document;
use Kern\Documents\ObjectStore\EncryptedObjectStore;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\TaxRomania\StatementImport\Enums\StatementImportStatus;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

/**
 * Lookalike transactions: gathered, merged, or kept — never refused at import.
 *
 * Two 12-lei coffees on the same day and one coffee imported twice look identical, and only the
 * person who drank them can tell. So nothing is dropped on the way in; this is where a person
 * decides, and where merging has to keep every piece of evidence the discarded rows carried.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/** @return array{0: User, 1: string, 2: string} user, subject id, account id */
function ledger(): array
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

    $account = FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::OTHER,
        'display_name_ciphertext' => 'Cont',
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    return [$user, $subject->id, (string) $account->id];
}

/** @param array<string, mixed> $overrides */
function payment(string $subjectId, string $accountId, array $overrides = []): FinancialTransaction
{
    return FinancialTransaction::create([
        'tax_subject_id' => $subjectId,
        'account_id' => $accountId,
        'booked_at' => '2026-03-04',
        'amount_minor' => -1340,
        'currency_code' => 'RON',
        'description_ciphertext' => 'Cumpărături',
        'counterparty_ciphertext' => 'Kaufland Romania SCS',
        'source' => RecordSource::MANUAL,
        ...$overrides,
    ]);
}

/** A READY document with one stored page holding exactly these bytes. */
function documentWithPage(string $subjectId, string $bytes): Document
{
    $document = Document::factory()
        ->part(['mime_type' => 'image/png', 'sha256' => hash('sha256', $bytes), 'status' => DocumentStatus::READY])
        ->create(['tax_subject_id' => $subjectId, 'status' => DocumentStatus::READY]);

    $stream = fopen('php://temp', 'w+b');
    assert($stream !== false);
    fwrite($stream, $bytes);
    rewind($stream);
    app(EncryptedObjectStore::class)->put($document->parts->sole()->objectKey(), $stream);
    fclose($stream);

    return $document->fresh(['parts']) ?? $document;
}

it('gathers a receipt and the statement row for the same payment', function (): void {
    [$user, $subjectId, $accountId] = ledger();

    // The case the whole view exists for: the same shop, spelt two ways by two sources.
    payment($subjectId, $accountId, ['counterparty_ciphertext' => 'Kaufland Romania SCS']);
    payment($subjectId, $accountId, ['counterparty_ciphertext' => 'KAUFLAND 1234 BUCURESTI']);

    // Same figures, a different shop: not a lookalike, however much the amounts agree.
    payment($subjectId, $accountId, ['counterparty_ciphertext' => 'Lidl Romania SCS']);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates")
        ->assertOk()
        ->assertJsonCount(1, 'groups')
        ->assertJsonCount(2, 'groups.0.transactions');
});

it('stops raising a group a person kept, until an identical payment arrives', function (): void {
    [$user, $subjectId, $accountId] = ledger();

    $first = payment($subjectId, $accountId);
    $second = payment($subjectId, $accountId);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates/keep", [
            'transaction_ids' => [$first->id, $second->id],
        ])
        ->assertNoContent();

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates")
        ->assertJsonCount(0, 'groups');

    // A third coffee is a new question, even though the first two were settled.
    payment($subjectId, $accountId);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates")
        ->assertJsonCount(1, 'groups')
        ->assertJsonCount(3, 'groups.0.transactions');
});

it('keeps the document-backed row, and carries a classification across to it', function (): void {
    [$user, $subjectId, $accountId] = ledger();

    // Typed by hand first, and already classified.
    $typed = payment($subjectId, $accountId, ['classification_status' => TransactionClassificationStatus::CLASSIFIED]);
    TransactionSplit::create([
        'tax_subject_id' => $subjectId,
        'transaction_id' => $typed->id,
        'amount_minor' => -1340,
        'category_code' => 'EXPENSE',
    ]);

    // Imported from a receipt later, unclassified — but it is the one with the evidence.
    $document = Document::factory()->create(['tax_subject_id' => $subjectId, 'status' => DocumentStatus::READY]);
    $imported = payment($subjectId, $accountId, ['document_id' => $document->id, 'source' => RecordSource::DOCUMENT]);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates")
        ->assertJsonPath('groups.0.survivor_id', $imported->id);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates/merge", [
            'transaction_ids' => [$typed->id, $imported->id],
        ])
        ->assertOk()
        ->assertJsonPath('survivor_id', $imported->id);

    $survivor = FinancialTransaction::query()->sole();

    // One payment, still classified: merging must not send it back to the worklist.
    expect($survivor->id)->toBe($imported->id)
        ->and($survivor->document_id)->toBe($document->id)
        ->and($survivor->classification_status)->toBe(TransactionClassificationStatus::CLASSIFIED)
        ->and(TransactionSplit::query()->where('transaction_id', $survivor->id)->sole()->category_code)->toBe('EXPENSE');
});

it('folds the discarded rows’ documents into the survivor’s, as further pages', function (): void {
    [$user, $subjectId, $accountId] = ledger();

    $receipt = documentWithPage($subjectId, 'the receipt photograph');
    $invoice = documentWithPage($subjectId, 'the invoice scan');

    $kept = payment($subjectId, $accountId, ['document_id' => $receipt->id]);
    $discarded = payment($subjectId, $accountId, ['document_id' => $invoice->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates/merge", [
            'transaction_ids' => [$kept->id, $discarded->id],
        ])
        ->assertOk();

    $merged = Document::query()->with('parts')->findOrFail($receipt->id);

    // Both documents backed the rows; the older row survives, and its document now holds both pages.
    expect(Document::query()->find($invoice->id))->toBeNull()
        ->and($merged->part_count)->toBe(2)
        ->and($merged->parts->pluck('position')->all())->toBe([1, 2]);

    // The moved page was re-encrypted under the survivor's path, not merely relabelled — so it
    // still reads back, from where the row now says it is.
    $moved = $merged->parts->last();
    assert($moved !== null);
    $stream = app(EncryptedObjectStore::class)->get($moved->objectKey());

    expect(stream_get_contents($stream))->toBe('the invoice scan')
        ->and($moved->object_path)->toContain($receipt->id);
});

it('keeps a page once when the same photograph backed both rows', function (): void {
    [$user, $subjectId, $accountId] = ledger();

    $first = documentWithPage($subjectId, 'one photograph, uploaded twice');
    $second = documentWithPage($subjectId, 'one photograph, uploaded twice');

    $kept = payment($subjectId, $accountId, ['document_id' => $first->id]);
    $discarded = payment($subjectId, $accountId, ['document_id' => $second->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates/merge", [
            'transaction_ids' => [$kept->id, $discarded->id],
        ])
        ->assertOk();

    expect(Document::query()->findOrFail($first->id)->part_count)->toBe(1);
});

/** A statement import the lines below can say they came from. */
function importedFrom(string $subjectId, string $accountId): string
{
    return (string) StatementImport::create([
        'tax_subject_id' => $subjectId,
        'account_id' => $accountId,
        'status' => StatementImportStatus::IMPORTED,
    ])->id;
}

/**
 * A bank lists every movement on its own line: eight coffees of the same price on one day are eight
 * coffees. Offering them as duplicates invited merging real payments away.
 */
it('never gathers two lines of the same statement, however alike', function (): void {
    [$user, $subjectId, $accountId] = ledger();
    $import = importedFrom($subjectId, $accountId);

    payment($subjectId, $accountId, ['import_id' => $import]);
    payment($subjectId, $accountId, ['import_id' => $import]);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates")
        ->assertOk()
        ->assertJsonCount(0, 'groups');

    // A typed row naming nobody matches anything — and must still not link the two lines into one
    // group through itself. It is a lookalike of one of them, which is a question worth asking.
    payment($subjectId, $accountId, ['counterparty_ciphertext' => '']);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates")
        ->assertJsonCount(1, 'groups')
        ->assertJsonCount(2, 'groups.0.transactions');

    // Two different statements covering the same day are exactly the case the view is for.
    payment($subjectId, $accountId, ['import_id' => importedFrom($subjectId, $accountId)]);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates")
        ->assertJsonCount(1, 'groups');
});

it('refuses to merge two lines of the same statement', function (): void {
    [$user, $subjectId, $accountId] = ledger();
    $import = importedFrom($subjectId, $accountId);

    $first = payment($subjectId, $accountId, ['import_id' => $import]);
    $second = payment($subjectId, $accountId, ['import_id' => $import]);

    // The view never offers this; the merge is the step that deletes, so it refuses on its own too.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates/merge", [
            'transaction_ids' => [$first->id, $second->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'NOT_DUPLICATES');

    expect(DB::table('financial_transactions')->count())->toBe(2);
});

it('refuses to merge transactions that are not lookalikes', function (): void {
    [$user, $subjectId, $accountId] = ledger();

    $coffee = payment($subjectId, $accountId);
    $rent = payment($subjectId, $accountId, ['amount_minor' => -250000]);

    // A merge deletes rows. Two unrelated payments named together must not lose one of them.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates/merge", [
            'transaction_ids' => [$coffee->id, $rent->id],
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'NOT_DUPLICATES');

    expect(FinancialTransaction::query()->count())->toBe(2);
});

it('never merges a transaction belonging to someone else', function (): void {
    [$user, $subjectId, $accountId] = ledger();
    [, $otherSubjectId, $otherAccountId] = ledger();

    $mine = payment($subjectId, $accountId);
    $theirs = payment($otherSubjectId, $otherAccountId);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions/duplicates/merge", [
            'transaction_ids' => [$mine->id, $theirs->id],
        ])
        ->assertStatus(422);

    expect(DB::table('financial_transactions')->count())->toBe(2);
});
