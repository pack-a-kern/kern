<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\CounterpartyDirectoryTest;

use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Enums\TransactionClassificationStatus;
use Kern\TaxRomania\Wallet\Models\Counterparty;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;
use Kern\TaxRomania\Wallet\Models\Tag;
use Kern\TaxRomania\Wallet\Models\TransactionSplit;
use Kern\TaxRomania\Wallet\Services\CounterpartyDirectory;
use Tests\TestCase;

/**
 * Every counterparty this taxpayer has, whether or not anybody has decided anything about it.
 *
 * The table used to hold only decisions — two rows for 317 names on the development ledger — which
 * made three questions expensive that should be cheap: which names exist, which nobody has answered,
 * and which of them contain the text somebody just typed.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string, 2: string} */
function directorySubject(): array
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

function record(TestCase $case, User $user, string $subjectId, string $accountId, int $amount, string $counterparty): void
{
    $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => $amount,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-05',
        'description' => 'Plată',
        'counterparty' => $counterparty,
    ])->assertCreated();
}

it('writes a counterparty down the first time money moves under it', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');

    $row = Counterparty::query()->sole();

    expect((string) $row->counterparty_ciphertext)->toBe('Mega Image')
        // Known, not decided: those are different states, and the worklist is the second one.
        ->and($row->category_code)->toBeNull()
        ->and($row->source)->toBeNull()
        ->and($row->analysed_at)->toBeNull();
});

it('writes it once however often the shop is paid', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');
    record($this, $user, $subjectId, $accountId, -8_000, 'Mega Image');
    record($this, $user, $subjectId, $accountId, -5_000, 'Mega Image');

    expect(Counterparty::query()->count())->toBe(1);
});

it('keeps the two directions apart, because they mean different things', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    // Money to a friend is a loan and money from them is its repayment. One row for both would make
    // one answer serve two questions.
    record($this, $user, $subjectId, $accountId, -50_000, 'Grigore Andrei');
    record($this, $user, $subjectId, $accountId, 50_000, 'Grigore Andrei');

    expect(Counterparty::query()->count())->toBe(2)
        ->and(
            Counterparty::query()
                ->get()
                ->map(static fn (Counterparty $row): string => $row->direction->value)
                ->sort()
                ->values()
                ->all(),
        )->toBe(['IN', 'OUT']);
});

it('never disturbs an answer somebody already gave', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');

    $row = Counterparty::query()->sole();
    $row->category_code = 'EXPENSE';
    $row->save();

    // The same shop, paid again. The point of the table is that this does not ask again.
    record($this, $user, $subjectId, $accountId, -9_000, 'Mega Image');

    expect(Counterparty::query()->sole()->category_code)->toBe('EXPENSE');
});

it('takes in a corrected counterparty, and leaves the old one alone', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Imag');

    /** @var string $transactionId */
    $transactionId = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")
        ->json('transactions.0.id');

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transactionId}", [
            'counterparty' => 'Mega Image',
        ])->assertOk();

    // Both: other transactions may still carry the old spelling, and a directory that forgot a name
    // the moment one row stopped using it would be wrong more often than tidy.
    expect(Counterparty::query()->count())->toBe(2);
});

it('finds a counterparty by part of its name, which no query could do', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'DIGI ROMANIA SA');
    record($this, $user, $subjectId, $accountId, -8_000, 'Mega Image');

    /*
     * The name is encrypted and the index beside it is a hash of the whole name, so neither column
     * can be matched against a fragment. This decrypts and matches in PHP, and hands back the hash
     * — which is what the ledger then filters on, so no plaintext name goes back to the server.
     */
    $found = app(CounterpartyDirectory::class)->search('digi');

    expect($found)->toHaveCount(1)
        ->and($found[0]['name'])->toBe('DIGI ROMANIA SA')
        ->and($found[0]['indexes'])->toHaveCount(1);
});

it('ignores case and accents when searching, as the rest of the application does', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Librăria Cărturești');

    expect(app(CounterpartyDirectory::class)->search('carturesti'))->toHaveCount(1);
});

it('returns one result for a name paid and received, with both its indexes', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -50_000, 'Revolut');
    record($this, $user, $subjectId, $accountId, 20_000, 'Revolut');

    // Direction is identity everywhere else here and deliberately not in the search: a person
    // looking for a name wants the name, whichever way the money went.
    $found = app(CounterpartyDirectory::class)->search('revolut');

    expect($found)->toHaveCount(1)
        ->and($found[0]['indexes'])->toHaveCount(1);
});

it('answers nothing for an empty search rather than everything', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');

    expect(app(CounterpartyDirectory::class)->search(''))->toBe([])
        ->and(app(CounterpartyDirectory::class)->search('   '))->toBe([]);
});

it('narrows the ledger by what was typed, across every spelling of it', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    // One shop, written three ways, as a bank writes it. Filtering by one of the three answers with
    // a third of the rows — which looks like an answer and is not.
    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');
    record($this, $user, $subjectId, $accountId, -8_000, 'Mega Image Oltenit');
    record($this, $user, $subjectId, $accountId, -5_000, 'Mega Image Glucoza');
    record($this, $user, $subjectId, $accountId, -9_000, 'Lidl Ro-215');

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?counterparty=mega")
        ->assertOk();

    expect($response->json('total'))->toBe(3);
});

it('ignores case and accents in the ledger filter too', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Librăria Cărturești');

    expect(
        $this->actingAs($user)
            ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?counterparty=CARTURESTI")
            ->assertOk()
            ->json('total'),
    )->toBe(1);
});

it('answers with nothing for a name this person has never paid', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');

    // Not "no filter": a search that matches nothing must answer with an empty ledger rather than
    // with all of it, or a typo silently looks like everything you own.
    expect(
        $this->actingAs($user)
            ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?counterparty=zzzz")
            ->assertOk()
            ->json('total'),
    )->toBe(0);
});

it('needs two letters before it narrows anything', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, -12_000, 'Mega Image');
    record($this, $user, $subjectId, $accountId, -8_000, 'Lidl Ro-215');

    // One letter matches most of a directory and every match costs a decrypt, so it is not a
    // search — the ledger is left alone rather than filtered arbitrarily.
    expect(
        $this->actingAs($user)
            ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?counterparty=m")
            ->assertOk()
            ->json('total'),
    )->toBe(2);
});

/**
 * Answering one transaction answers that transaction.
 *
 * Not the party's other rows. One of a party's payments is routinely unlike the rest — a transfer to
 * somebody you usually lend to can be a gift — and pushing the answer across every unanswered row
 * would make that exception impossible to state. The remembering still happens, so the next
 * statement naming the party arrives with the answer; saying something about the whole party is the
 * counterparties screen's job, and it reports how many rows it will change first.
 */
it('leaves the counterparty other rows alone when one transaction is classified', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, 9_000, 'Bruma Mihaela');
    record($this, $user, $subjectId, $accountId, 4_000, 'Bruma Mihaela');

    $first = FinancialTransaction::query()->where('amount_minor', 9_000)->sole();

    $this->actingAs($user)
        ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$first->id}/splits", [
            'splits' => [['amount_minor' => $first->amount_minor, 'category_code' => 'LOAN_REPAYMENT']],
        ])->assertNoContent();

    expect(FinancialTransaction::query()->where('amount_minor', 9_000)->sole()->classification_status)
        ->toBe(TransactionClassificationStatus::CLASSIFIED)
        ->and(FinancialTransaction::query()->where('amount_minor', 4_000)->sole()->classification_status)
        ->toBe(TransactionClassificationStatus::UNCLASSIFIED);

    // Remembered all the same, so the next statement naming this party arrives answered.
    expect(Counterparty::query()->sole()->category_code)->toBe('LOAN_REPAYMENT');
});

/** An answer somebody already gave is theirs, and a rule decided later does not overrule it. */
it('leaves a transaction that already has an answer alone', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, 9_000, 'Bruma Mihaela');
    record($this, $user, $subjectId, $accountId, 4_000, 'Bruma Mihaela');

    // The first is answered, then the second is answered differently. The rule the second writes
    // must not reach back over the first.
    foreach ([[9_000, 'OTHER_INCOME'], [4_000, 'LOAN_REPAYMENT']] as [$amount, $category]) {
        $transaction = FinancialTransaction::query()->where('amount_minor', $amount)->sole();

        $this->actingAs($user)
            ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transaction->id}/splits", [
                'splits' => [['amount_minor' => $transaction->amount_minor, 'category_code' => $category]],
            ])->assertNoContent();
    }

    expect(FinancialTransaction::query()->where('amount_minor', 9_000)->sole()->splits()->sole()->category_code)
        ->toBe('OTHER_INCOME');
});

/**
 * Several spellings of one party become one, and every transaction follows.
 *
 * The re-sealing is the part worth proving. A ciphertext is bound to the row it sits in, so a merge
 * cannot copy one blob across the rows it renames — each seals its own. Reading every name back
 * afterwards is what would catch a single-`UPDATE` implementation: it would throw rather than
 * return the wrong name.
 */
it('folds several spellings into one name and rewrites their transactions', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, 9_000, 'Bruma Mihaela');
    record($this, $user, $subjectId, $accountId, 4_000, 'Mihaela Bruma');
    record($this, $user, $subjectId, $accountId, 2_000, 'BRUMA ZINA');
    record($this, $user, $subjectId, $accountId, 1_000, 'Altcineva');

    /** @var list<string> $ids */
    $ids = Counterparty::query()
        ->whereIn('counterparty_blind_index', FinancialTransaction::query()
            ->where('amount_minor', '!=', 1_000)
            ->pluck('counterparty_blind_index'))
        ->pluck('id')
        ->all();

    expect($ids)->toHaveCount(3);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/counterparties/merge", [
            'counterparty_ids' => $ids,
            'name' => 'Bruma',
        ])
        ->assertOk()
        // All three, not two: `Bruma` is a name none of them had, so every row is rewritten. Merging
        // into one of the existing spellings would leave that one's rows alone.
        ->assertJsonPath('transactions_updated', 3);

    // Every row now names the survivor, and every one of them decrypts — which a shared ciphertext
    // would not.
    $merged = FinancialTransaction::query()->where('amount_minor', '!=', 1_000)->get();

    expect($merged)->toHaveCount(3);

    foreach ($merged as $transaction) {
        expect($transaction->counterparty_ciphertext)->toBe('Bruma');
    }

    // One index for the three of them, and one rule row left.
    expect($merged->pluck('counterparty_blind_index')->unique())->toHaveCount(1)
        ->and(Counterparty::query()->count())->toBe(2);

    // The party that was not merged is untouched.
    expect(FinancialTransaction::query()->where('amount_minor', 1_000)->sole()->counterparty_ciphertext)
        ->toBe('Altcineva');
});

/**
 * The parties screen states what a party is, and may say so over an answer already given.
 *
 * A split records no author, so nothing can tell a model's answer from the taxpayer's — which is why
 * replacing is something a person asks for in front of a count, and never the default. Without the
 * flag the rows are left exactly as they were.
 */
it('replaces answers already given only when asked to', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, 9_000, 'Safegrid Network Srl');
    record($this, $user, $subjectId, $accountId, 4_000, 'Safegrid Network Srl');

    foreach (FinancialTransaction::query()->get() as $transaction) {
        $this->actingAs($user)
            ->putJson("/api/v1/subjects/{$subjectId}/wallet/transactions/{$transaction->id}/splits", [
                'splits' => [['amount_minor' => $transaction->amount_minor, 'category_code' => 'FREELANCE']],
            ])->assertNoContent();
    }

    $rule = Counterparty::query()->sole();

    // The listing tells the screen what replacing would cost.
    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/counterparties")
        ->assertOk()
        ->assertJsonPath('counterparties.0.in.answered', 2);

    // Without the flag: nothing moves, because both rows are answered.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/counterparties/{$rule->id}", [
            'category_code' => 'DIVIDENDS_INTEREST',
        ])
        ->assertOk()
        ->assertJsonPath('transactions_updated', 0);

    expect(TransactionSplit::query()->pluck('category_code')->unique()->all())->toBe(['FREELANCE']);

    // With it: both take the new answer, and each keeps exactly one split.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/counterparties/{$rule->id}", [
            'category_code' => 'DIVIDENDS_INTEREST',
            'replace' => true,
        ])
        ->assertOk()
        ->assertJsonPath('transactions_updated', 2);

    expect(TransactionSplit::query()->count())->toBe(2)
        ->and(TransactionSplit::query()->pluck('category_code')->unique()->all())->toBe(['DIVIDENDS_INTEREST']);
});

/**
 * A tag named on a party is created if the taxpayer has not used it before.
 *
 * Names rather than ids, the same shape the classify dialog posts — so a tag can be typed where it
 * is needed instead of being made somewhere else first and chosen here.
 */
it('creates a tag named on a counterparty', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, 9_000, 'Bruma Mihaela');

    $rule = Counterparty::query()->sole();

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/counterparties/{$rule->id}", [
            'category_code' => 'LOAN_REPAYMENT',
            'tags' => ['împrumut familie'],
        ])
        ->assertOk();

    expect(Tag::query()->count())->toBe(1)
        ->and(Counterparty::query()->findOrFail($rule->id)->tags()->sole()->name_ciphertext)
        ->toBe('împrumut familie');
});

/**
 * Forgetting a party clears what was decided about it and leaves the money alone.
 *
 * The distinction is the whole point: a party is what the ledger remembers *about* a name, so
 * forgetting one must not be a way to lose a year of records by tidying a list of names.
 */
it('forgets a counterparty without touching its transactions', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    record($this, $user, $subjectId, $accountId, 9_000, 'Bruma Mihaela');
    record($this, $user, $subjectId, $accountId, 4_000, 'Altcineva');

    /** @var list<string> $ids */
    $ids = Counterparty::query()->pluck('id')->all();

    expect($ids)->toHaveCount(2);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/counterparties/forget", [
            'counterparty_ids' => [$ids[0]],
        ])
        ->assertOk()
        ->assertJsonPath('counterparties_forgotten', 1);

    expect(Counterparty::query()->count())->toBe(1)
        // Every transaction is exactly where it was.
        ->and(FinancialTransaction::query()->count())->toBe(2);
});

/**
 * A search answers with every match, because merging is the reason to search.
 *
 * Paged, a merge could only reach the spellings that happened to land on the first page — which is
 * the opposite of what searching for them was for.
 */
it('does not page a search', function (): void {
    [$user, $subjectId, $accountId] = directorySubject();

    foreach (['Bruma Mihaela', 'Mihaela Bruma', 'BRUMA ZINA', 'Altcineva'] as $index => $name) {
        record($this, $user, $subjectId, $accountId, ($index + 1) * 1_000, $name);
    }

    // A page size of one would hold a single party, and does for browsing.
    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/counterparties?per_page=1")
        ->assertOk()
        ->assertJsonCount(1, 'counterparties')
        ->assertJsonPath('total', 4);

    // The same page size while searching returns all three spellings, ready to be merged together.
    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/counterparties?per_page=1&q=bruma")
        ->assertOk()
        ->assertJsonCount(3, 'counterparties')
        ->assertJsonPath('total', 3);
});
