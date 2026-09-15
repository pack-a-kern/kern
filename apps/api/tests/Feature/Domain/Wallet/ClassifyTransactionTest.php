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
                ['amount_minor' => 50_000, 'category_code' => 'NOT_INCOME'],
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

    foreach (['SALARY_PENSION', 'FREELANCE'] as $category) {
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

    /** @var list<array{code: string, income: bool, direction: string, preselected: bool}> $categories */
    $categories = $response->json('categories');
    $codes = array_column($categories, 'code');

    // One source for the dropdown and the validator: two lists drift, and the way that shows up is
    // an option refused on save.
    /** @var array<string, string> $income */
    $income = config('tax_categories.income');
    /** @var array<string, string> $notIncome */
    $notIncome = config('tax_categories.not_income');
    $byCode = array_column($categories, null, 'code');

    expect($codes)->toBe([...array_keys($income), ...array_keys($notIncome)])
        ->and($byCode['RENT']['income'])->toBeTrue()
        ->and($byCode['TRANSFER']['income'])->toBeFalse()
        // The screen filters by these, so a list it shows can never hold a category the save refuses.
        ->and($byCode['DIVIDENDS']['direction'])->toBe('in')
        ->and($byCode['EXPENSE']['direction'])->toBe('out')
        ->and($byCode['LOAN']['direction'])->toBe('either')
        ->and($byCode['EXPENSE']['preselected'])->toBeTrue()
        ->and($byCode['DIVIDENDS']['preselected'])->toBeFalse();
});

it('accepts the answer while the transaction is being typed', function (): void {
    [$user, $subjectId, ] = classifiable($this);

    /** @var list<array{id: string}> $accounts */
    $accounts = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->json('accounts');

    // Someone entering a transaction by hand already knows what it was; asking again afterwards in
    // a separate dialog is work for nothing.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accounts[0]['id'],
        'amount_minor' => 120_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-04-01',
        'description' => 'Chirie aprilie',
        'category_code' => 'RENT',
        'country_code' => 'DE',
    ])->assertCreated();

    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('transactions');
    $classified = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['classification_status'] === 'CLASSIFIED',
    ));

    expect($classified)->toHaveCount(1);

    /** @var list<array{category_code: string, country_code: string|null}> $splits */
    $splits = $classified[0]['splits'];

    expect($splits[0]['category_code'])->toBe('RENT')
        ->and($splits[0]['country_code'])->toBe('DE');
});

it('leaves a transaction unanswered when no category is given', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    // The worklist depends on this: a row nobody has answered must be distinguishable from one
    // answered badly, so an omitted category is "not yet", never a default.
    expect(FinancialTransaction::query()->findOrFail($transactionId)->classification_status->value)
        ->toBe('UNCLASSIFIED');
});

it('changes the figures and the answer in one edit', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 450_000, 'category_code' => 'SALARY_PENSION']],
        ])
        ->assertNoContent();

    // What the screen does when someone edits a row: the amount and the answer move together, and
    // the split follows the new amount rather than being left pointing at the old one.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}", [
            'amount_minor' => 500_000,
            'description' => 'Chirie corectată',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 500_000, 'category_code' => 'RENT']],
        ])
        ->assertNoContent();

    /** @var list<TransactionSplit> $splits */
    $splits = TransactionSplit::query()->where('transaction_id', $transactionId)->get()->all();

    expect($splits)->toHaveCount(1)
        ->and($splits[0]->category_code)->toBe('RENT')
        ->and($splits[0]->amount_minor)->toBe(500_000)
        ->and(FinancialTransaction::query()->findOrFail($transactionId)->amount_minor)->toBe(500_000);
});

it('refuses a category that cannot have the direction of the money', function (): void {
    // Money in: an expense cannot be what arrived.
    [$user, $subjectId, $transactionId] = classifiable($this);

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}/splits", [
            'splits' => [['amount_minor' => 450_000, 'category_code' => 'EXPENSE']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'CATEGORY_DIRECTION');

    expect(TransactionSplit::query()->where('transaction_id', $transactionId)->count())->toBe(0);
});

it('refuses income typed on money going out, and lets a loan go either way', function (): void {
    [$user, $subjectId, $transactionId] = classifiable($this);
    $accountId = FinancialTransaction::query()->findOrFail($transactionId)->account_id;

    $outgoing = static fn (string $category): array => [
        'account_id' => $accountId,
        'amount_minor' => -120_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-04',
        'description' => 'Plată',
        'category_code' => $category,
    ];

    // A dividend recorded on money that left would be declared as income nobody received.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", $outgoing('DIVIDENDS'))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'CATEGORY_DIRECTION');

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", $outgoing('LOAN'))
        ->assertCreated();
});
