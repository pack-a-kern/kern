<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

/**
 * A subject with one stream source and one unclassified transaction in it.
 *
 * Its own helper rather than the one in `WalletMutationTest`: a function defined in a Pest test file
 * belongs to that file, and sharing it would mean moving it to `tests/Pest.php` for two callers.
 *
 * @return array{0: User, 1: string, 2: string}
 */
function classifiable(Tests\TestCase $case): array
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

    $subjectId = (string) $subject->id;

    $opened = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont curent',
        'kind' => 'OTHER',
        'currency_code' => 'RON',
    ])->assertCreated();

    /** @var string $accountId */
    $accountId = $opened->json('id');

    $created = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => 450_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-03',
        'description' => 'TRANSFER POPESCU ION',
    ])->assertCreated();

    /** @var string $transactionId */
    $transactionId = $created->json('id');

    return [$user, $subjectId, $transactionId];
}

it('records what a transaction was, and says so on the list', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 450_000, 'category_code' => 'RENT']],
        ])
        ->assertNoContent();

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk()
        ->assertJsonPath('transactions.0.classification_status', 'CLASSIFIED')
        ->assertJsonPath('transactions.0.splits.0.category_code', 'RENT')
        // Null, not 'RO': the column means "somewhere other than here", and storing RO everywhere
        // would make a foreign row indistinguishable from a domestic one by query.
        ->assertJsonPath('transactions.0.splits.0.country_code', null);
});

it('splits one payment into two meanings', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    // 4.000 rent and 500 the tenant repaying a water bill: one payment, two answers, taxed
    // differently. A single category would have to lose one of them.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [
                ['amount_minor' => 400_000, 'category_code' => 'RENT'],
                ['amount_minor' => 50_000, 'category_code' => 'REIMBURSEMENT'],
            ],
        ])
        ->assertNoContent();

    expect(TransactionSplit::query()->where('transaction_id', $transactionId)->count())->toBe(2);
});

it('refuses splits that do not add up to the transaction', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    // The remainder would be income nobody has an answer for, silently missing from a return.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 400_000, 'category_code' => 'RENT']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'SPLIT_TOTAL_MISMATCH');

    expect(TransactionSplit::query()->where('transaction_id', $transactionId)->count())->toBe(0);
});

it('refuses a category that is not in the catalogue', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    // The screen offers exactly what the server accepts, so anything else is a crafted request
    // rather than a mistake someone could make in the form.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 450_000, 'category_code' => 'BANANA']],
        ])
        ->assertStatus(422);
});

it('replaces a previous answer instead of adding to it', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    foreach (['SALARY', 'FREELANCE'] as $category) {
        $this->actingAs($user)
            ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
                'splits' => [['amount_minor' => 450_000, 'category_code' => $category]],
            ])
            ->assertNoContent();
    }

    // A correction is the normal case; merging would leave the transaction claiming to be both,
    // with amounts that no longer add up.
    /** @var list<TransactionSplit> $splits */
    $splits = TransactionSplit::query()->where('transaction_id', $transactionId)->get()->all();

    expect($splits)->toHaveCount(1)
        ->and($splits[0]->category_code)->toBe('FREELANCE');
});

it('returns a transaction to the worklist when the answer is cleared', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 450_000, 'category_code' => 'RENT']],
        ])
        ->assertNoContent();

    // Someone who realises the label was wrong but does not yet know the right one needs a way
    // back to "not answered", which is different from answering it badly.
    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", ['splits' => []])
        ->assertNoContent();

    expect(FinancialTransaction::query()->findOrFail($transactionId)->classification_status->value)
        ->toBe('UNCLASSIFIED');
});

it('keeps the country beside the category, because the form declares them together', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 450_000, 'category_code' => 'RENT', 'country_code' => 'DE']],
        ])
        ->assertNoContent();

    // Declarația Unică declares foreign income per country *and* per type, so RENT+DE and RENT+RO
    // are different rows on the form — a single FOREIGN_INCOME code would collapse that.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk()
        ->assertJsonPath('transactions.0.splits.0.category_code', 'RENT')
        ->assertJsonPath('transactions.0.splits.0.country_code', 'DE');
});

it('offers the screen exactly the categories it will accept', function (): void {
    [$user, $subjectId] = classifiable($this);

    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk();

    /** @var list<array{code: string, income: bool}> $categories */
    $categories = $response->json('categories');
    $codes = array_column($categories, 'code');

    // One source for the dropdown and the validator: two lists drift, and the way that shows up is
    // an option refused on save.
    /** @var list<string> $income */
    $income = config('tax_categories.income');
    /** @var list<string> $notIncome */
    $notIncome = config('tax_categories.not_income');

    expect($codes)->toBe([...$income, ...$notIncome])
        ->and(array_column($categories, 'income', 'code')['RENT'])->toBeTrue()
        ->and(array_column($categories, 'income', 'code')['TRANSFER'])->toBeFalse();
});
