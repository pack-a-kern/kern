<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\TransactionFilterOptionsTest;

use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Tests\TestCase;

/**
 * What the two filters above the ledger offer: the banks, and the sources.
 *
 * The register holds forty-six Romanian banks and a person's money sits at two or three, so a filter
 * offering the register is forty-odd choices that each return nothing. The account filter had the
 * same fault and an easier one to hit: it offered every account, including a cash holding, whose rows
 * this list never shows at all.
 *
 * The rule both answers follow is the one worth holding onto: **every filter in force narrows a list
 * except that list's own**. Filtering by a tag should leave the banks that tag appears at —
 * otherwise the filters disagree about which ledger they are describing. Filtering by a bank must
 * *not* leave only that bank, or changing banks means clearing the filter first and choosing again.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string, 2: string} the user, the subject, and an account to record into */
function bankFilterSubject(): array
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
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    return [$user, $subject->id, $account->id];
}

/** @param list<string> $tags */
function recordAt(TestCase $case, User $user, string $subjectId, string $accountId, ?string $bankId, int $amount, array $tags = []): void
{
    $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'bank_id' => $bankId,
        'amount_minor' => $amount,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-05',
        'description' => 'Plată',
        'tags' => $tags,
    ])->assertCreated();
}

/**
 * @param  array<string, string>  $query
 * @return list<string>
 */
function offered(TestCase $case, User $user, string $subjectId, string $key, array $query = []): array
{
    $suffix = $query === [] ? '' : '?' . http_build_query($query);

    /** @var list<string> $ids */
    $ids = $case->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions{$suffix}")
        ->assertOk()
        ->json($key);

    sort($ids);

    return $ids;
}

/**
 * @param  array<string, string>  $query
 * @return list<string>
 */
function offeredBanks(TestCase $case, User $user, string $subjectId, array $query = []): array
{
    return offered($case, $user, $subjectId, 'bank_ids_in_use', $query);
}

/**
 * @param  array<string, string>  $query
 * @return list<string>
 */
function offeredAccounts(TestCase $case, User $user, string $subjectId, array $query = []): array
{
    return offered($case, $user, $subjectId, 'account_ids_in_use', $query);
}

it('offers the banks the ledger holds, and not the rest of the register', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    $used = BankFactory::new()->createOne();
    // Registered, and no transaction anywhere near it. Before this list existed it was offered
    // anyway, alongside forty-five others.
    BankFactory::new()->createOne();

    recordAt($this, $user, $subjectId, $accountId, $used->id, -12_000);

    expect(offeredBanks($this, $user, $subjectId))->toBe([$used->id]);
});

it('leaves out a transaction recorded with no bank at all', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    recordAt($this, $user, $subjectId, $accountId, null, -9_000);

    expect(offeredBanks($this, $user, $subjectId))->toBe([]);
});

it('narrows the list by a tag, so the filters describe one ledger together', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    $tagged = BankFactory::new()->createOne();
    $other = BankFactory::new()->createOne();

    recordAt($this, $user, $subjectId, $accountId, $tagged->id, -12_000, ['combustibil']);
    recordAt($this, $user, $subjectId, $accountId, $other->id, -20_000, ['alimente']);

    // Unfiltered, both banks are in use.
    expect(offeredBanks($this, $user, $subjectId))->toHaveCount(2);

    /** @var list<array{id: string, name: string}> $tags */
    $tags = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/tags")->json('tags');
    $tagId = '';

    foreach ($tags as $tag) {
        $tagId = $tag['name'] === 'combustibil' ? $tag['id'] : $tagId;
    }

    // And under one tag, only the bank that tag's spending went through.
    expect($tagId)->not->toBe('')
        ->and(offeredBanks($this, $user, $subjectId, ['tag_id' => $tagId]))->toBe([$tagged->id]);
});

it('narrows the list by a date range as well', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    $march = BankFactory::new()->createOne();
    recordAt($this, $user, $subjectId, $accountId, $march->id, -12_000);

    expect(offeredBanks($this, $user, $subjectId, ['from' => '2026-04-01']))->toBe([])
        ->and(offeredBanks($this, $user, $subjectId, ['from' => '2026-03-01']))->toBe([$march->id]);
});

it('does not narrow the list by the bank already chosen', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    $first = BankFactory::new()->createOne();
    $second = BankFactory::new()->createOne();

    recordAt($this, $user, $subjectId, $accountId, $first->id, -12_000);
    recordAt($this, $user, $subjectId, $accountId, $second->id, -20_000);

    // This is the whole point of taking the filters apart instead of passing them through: with the
    // bank filter on, the dropdown still offers the other bank, so switching is one click and not
    // clear-then-choose.
    expect(offeredBanks($this, $user, $subjectId, ['bank_id' => $first->id]))
        ->toBe(offeredBanks($this, $user, $subjectId));
});

it('offers nothing for a row the ledger itself does not show', function (): void {
    [$user, $subjectId] = bankFilterSubject();

    // A cash holding's declared value is not a payment anybody made, and the list leaves it out. No
    // route can currently attach a bank to one — `RecordFinancialTransaction` refuses a holding
    // source outright, and the two places that write these rows directly set no `bank_id` — so this
    // is written by hand, exactly as they write it.
    //
    // Which is the point: the filter and the list read the same base query, so a row the list hides
    // cannot put a bank in the filter. Were they two queries, that would hold only by coincidence,
    // and the coincidence would end the first time one of these rows gained a bank.
    $cashBank = BankFactory::new()->createOne();

    $cash = FinancialAccount::create([
        'tax_subject_id' => $subjectId,
        'kind' => AccountKind::CASH,
        'display_name_ciphertext' => 'Numerar',
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    FinancialTransaction::create([
        'tax_subject_id' => $subjectId,
        'account_id' => $cash->id,
        'bank_id' => $cashBank->id,
        'booked_at' => '2026-03-05',
        'amount_minor' => 5_000_00,
        'currency_code' => 'RON',
        'description_ciphertext' => 'Numerar',
        'counterparty_ciphertext' => '',
        'classification_status' => TransactionClassificationStatus::UNCLASSIFIED,
        'source' => RecordSource::MANUAL,
    ]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")
        ->assertOk();

    expect($response->json('total'))->toBe(0)
        ->and($response->json('bank_ids_in_use'))->toBe([]);
});

it('offers the sources with rows, and not a cash holding that can never have one', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    recordAt($this, $user, $subjectId, $accountId, null, -12_000);

    // Opened through the action, so this is the cash holding the application actually writes: one
    // declared value, on an account whose rows the list excludes entirely. Offering it as a filter
    // was offering a choice that answered "no transactions" every time.
    $cash = $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'kind' => AccountKind::CASH->value,
        'display_name' => 'Numerar',
        'currency_code' => 'RON',
        'holding_amount_minor' => 5_000_00,
    ])->assertCreated()->json('id');

    expect($cash)->toBeString()
        ->and(offeredAccounts($this, $user, $subjectId))->toBe([$accountId]);
});

it('narrows the sources by a bank, and not by the source already chosen', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    $bank = BankFactory::new()->createOne();

    recordAt($this, $user, $subjectId, $accountId, $bank->id, -12_000);

    expect(offeredAccounts($this, $user, $subjectId, ['bank_id' => $bank->id]))->toBe([$accountId])
        ->and(offeredAccounts($this, $user, $subjectId, ['account_id' => $accountId]))
        ->toBe(offeredAccounts($this, $user, $subjectId));
});

/**
 * The tax filter names a category, not a state.
 *
 * It used to offer "classified", which named something nobody acts on: a person looking at what is
 * answered is looking for rent, or for dividends. The category is a property of a *split* rather
 * than of the transaction — one payment can be half rent and half spending — so this is the filter
 * that can silently match everything, and it did exactly that once before this test existed.
 */
it('narrows the ledger to one tax category', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    recordAt($this, $user, $subjectId, $accountId, null, -4_000);
    recordAt($this, $user, $subjectId, $accountId, null, 9_000);

    /** @var list<string> $ids */
    $ids = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")
        ->assertOk()
        ->json('transactions.*.id');

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$ids[0]}/splits", [
            'splits' => [['amount_minor' => 9_000, 'category_code' => 'RENT']],
        ])->assertNoContent();

    $rent = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?category_code=RENT")
        ->assertOk();

    expect($rent->json('total'))->toBe(1)
        ->and($rent->json('transactions.0.id'))->toBe($ids[0]);

    // And a category nobody used returns nothing rather than everything.
    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?category_code=SALARY_PENSION")
        ->assertOk()
        ->assertJsonPath('total', 0);
});

/**
 * A code outside the catalogue is refused rather than answered with an empty list.
 *
 * Silently returning nothing reads as "you have no such transactions", which is a different and
 * wrong answer to a filter that names a category that does not exist.
 */
it('refuses a tax category the catalogue does not have', function (): void {
    [$user, $subjectId] = bankFilterSubject();

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?category_code=ROYALTIES")
        ->assertStatus(422);
});

/**
 * The second page is the second page.
 *
 * `page` and `per_page` arrive as query strings, and the controller read them with `is_int`, which
 * is false for `'2'` — so every page returned the first one and the per-page default hid half of it.
 * Asserted over HTTP rather than against the query, because the query was never wrong: the defect
 * lived entirely in reading the request.
 */
it('pages the ledger', function (): void {
    [$user, $subjectId, $accountId] = bankFilterSubject();

    for ($amount = 1; $amount <= 5; $amount++) {
        recordAt($this, $user, $subjectId, $accountId, null, $amount * -1_000);
    }

    $pageOf = function (int $page) use ($user, $subjectId): array {
        /** @var list<string> $ids */
        $ids = $this->actingAs($user)
            ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?page={$page}&per_page=2")
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->json('transactions.*.id');

        return $ids;
    };

    $first = $pageOf(1);
    $second = $pageOf(2);
    $third = $pageOf(3);

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(2)
        ->and($third)->toHaveCount(1)
        // No row appears twice across the three pages, and together they are the whole ledger.
        ->and(array_unique([...$first, ...$second, ...$third]))->toHaveCount(5);
});
