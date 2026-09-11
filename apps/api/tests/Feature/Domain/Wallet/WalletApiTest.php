<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();
});

/**
 * A user with an active membership on a fresh subject, and that subject's id.
 *
 * Built through `SubjectMembership` rather than a raw insert, matching
 * `DocumentAiConsentControllerTest`: the table has no `status` column — an active membership is one
 * with `valid_from` in the past and `revoked_at` null — and hand-writing the insert got that wrong.
 *
 * @return array{0: User, 1: string}
 */
function walletSubject(): array
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

    return [$user, (string) $subject->getKey()];
}

it('refuses wallet reads for a subject the user has no membership on', function (): void {
    [$user] = walletSubject();
    $other = TaxSubject::factory()->create();

    // Cross-tenant access is the property every Wallet model's `ScopedToTaxSubject` exists to
    // guarantee; asserted at the HTTP boundary because that is where an attacker would try it.
    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$other->getKey()}/wallet/accounts")
        ->assertForbidden();
});

it('opens an account through the action, encrypting the name and IBAN at rest', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
            'display_name' => 'ING curent',
            'kind' => AccountKind::BANK_CURRENT->value,
            'currency_code' => 'RON',
            'institution_name' => 'ING Bank',
            'iban' => 'RO49AAAA1B31007593840000',
        ])
        ->assertCreated();

    // The raw row must not contain either value in the clear — the whole point of the DEK.
    $row = DB::table('financial_accounts')->where('tax_subject_id', $subjectId)->first();

    expect($row)->not->toBeNull()
        ->and((string) $row?->display_name_ciphertext)->not->toContain('ING curent')
        ->and((string) $row?->iban_ciphertext)->not->toContain('RO49AAAA')
        // The blind index is what makes an encrypted IBAN still matchable.
        ->and($row?->iban_blind_index)->not->toBeNull();

    $listed = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk();

    // Decrypted for display, but only the last four of the IBAN ever leave the server.
    $listed->assertJsonPath('accounts.0.display_name', 'ING curent')
        ->assertJsonPath('accounts.0.iban_last4', '0000')
        ->assertJsonMissing(['iban' => 'RO49AAAA1B31007593840000']);
});

it('reports an unknown balance rather than zero when no snapshot exists', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont nou',
        'kind' => AccountKind::BANK_CURRENT->value,
        'currency_code' => 'RON',
    ])->assertCreated();

    // A new account has an *unknown* balance, not an empty one. Zero would be a claim about the
    // account's contents that nothing supports.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balance_minor', null)
        ->assertJsonPath('accounts.0.balance_as_of', null);
});

it('reads the most recent balance snapshot, not the first or an arbitrary one', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont',
        'kind' => AccountKind::BANK_CURRENT->value,
        'currency_code' => 'RON',
    ])->assertCreated();

    $key = FinancialAccount::query()->firstOrFail()->getKey();
    $accountId = is_scalar($key) ? (string) $key : '';

    // Inserted oldest-last on purpose: a query that took "the first row" would pick the wrong one.
    foreach ([['2026-01-01', 1000], ['2026-03-01', 5000], ['2026-02-01', 3000]] as [$asOf, $balance]) {
        DB::table('account_balance_snapshots')->insert([
            'id' => (string) Str::uuid7(),
            'tax_subject_id' => $subjectId,
            'account_id' => $accountId,
            'balance_minor' => $balance,
            'as_of' => $asOf,
            'source' => 'MANUAL',
            'is_verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balance_minor', 5000);
});

it('totals balances per currency and never sums across them', function (): void {
    [$user, $subjectId] = walletSubject();

    foreach ([['RON', 70_000_00], ['EUR', 1_200_00], ['RON', 2_480_00]] as [$currency, $balance]) {
        $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
            'display_name' => "Cont {$currency} {$balance}",
            'kind' => AccountKind::BANK_CURRENT->value,
            'currency_code' => $currency,
        ])->assertCreated();
    }

    foreach (FinancialAccount::query()->get() as $index => $account) {
        DB::table('account_balance_snapshots')->insert([
            'id' => (string) Str::uuid7(),
            'tax_subject_id' => $subjectId,
            'account_id' => $account->getKey(),
            'balance_minor' => [70_000_00, 1_200_00, 2_480_00][$index] ?? 0,
            'as_of' => '2026-01-01',
            'source' => 'MANUAL',
            'is_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Read through the endpoint rather than `WalletQuery` directly: the query relies on the tenant
    // being bound, which the `tax-subject` middleware does as part of the request.
    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk();

    // Two keys, not one number: adding RON to EUR needs a rate, a date and a source, which is the
    // tax engine's job. A single total here would be an invented exchange rate.
    expect(array_keys((array) $response->json('totals.balances')))->toEqualCanonicalizing(['RON', 'EUR']);
});

it('rejects a deposit whose maturity precedes its opening', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'ING',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 50_000_00,
        'currency_code' => 'RON',
        'opened_at' => '2026-09-01',
        'maturity_at' => '2026-08-01',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL_365',
        'annual_rate' => '5.40',
    ])->assertStatus(422);
});

it('opens a deposit and reports its current rate and days to maturity', function (): void {
    [$user, $subjectId] = walletSubject();
    $opened = CarbonImmutable::now('UTC')->subMonth()->toDateString();
    $maturity = CarbonImmutable::now('UTC')->addMonths(5)->toDateString();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'BT',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 70_000_00,
        'currency_code' => 'RON',
        'opened_at' => $opened,
        'maturity_at' => $maturity,
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL_365',
        'annual_rate' => '5.75',
    ])->assertCreated();

    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk();

    // Computed from the two dates rather than written as a literal: the span of "a month ago to
    // five months from now" depends on which months those are.
    $expectedDays = (int) CarbonImmutable::parse($opened)->diffInDays(CarbonImmutable::parse($maturity));

    $response->assertJsonPath('accounts.0.deposit.principal_minor', 70_000_00)
        ->assertJsonPath('accounts.0.deposit.total_days', $expectedDays);

    // Computed at opening, with nothing to press: ~70,000 x 5.75% x 182/365.
    $interest = $response->json('accounts.0.deposit.interest_at_maturity_minor');

    expect(is_numeric($interest) ? (int) $interest : 0)
        ->toBeGreaterThan(1_900_00)
        ->toBeLessThan(2_100_00);

    // Compared as a number, not a string: the column is `numeric`, and its textual scale differs by
    // engine — Postgres answers "5.75000000" where the sqlite this suite runs on answers "5.75".
    // The rate itself is the assertion; its rendering is not.
    $rate = $response->json('accounts.0.deposit.annual_rate');
    $days = $response->json('accounts.0.deposit.days_to_maturity');

    expect(is_numeric($rate) ? (float) $rate : null)->toBe(5.75)
        ->and(is_numeric($days) ? (int) $days : null)->toBeGreaterThan(0);
});

it('records a balance as a new snapshot rather than overwriting the last one', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont',
        'kind' => AccountKind::BANK_CURRENT->value,
        'currency_code' => 'RON',
    ])->assertCreated();

    $key = FinancialAccount::query()->firstOrFail()->getKey();
    $accountId = is_scalar($key) ? (string) $key : '';

    foreach ([['2026-01-31', 100_000], ['2026-02-28', 250_000]] as [$asOf, $balance]) {
        $this->actingAs($user)
            ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$accountId}/balance", [
                'balance_minor' => $balance,
                'as_of' => $asOf,
                'is_verified' => true,
            ])
            ->assertCreated();
    }

    // Both observations survive: the history is what lets a past figure be defended.
    expect(DB::table('account_balance_snapshots')->where('account_id', $accountId)->count())->toBe(2);

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balance_minor', 250_000)
        ->assertJsonPath('accounts.0.balance_verified', true);
});

it('accepts a negative balance, because an overdraft is a real one', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont',
        'kind' => AccountKind::BANK_CURRENT->value,
        'currency_code' => 'RON',
    ])->assertCreated();

    $key = FinancialAccount::query()->firstOrFail()->getKey();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts/" . (is_scalar($key) ? (string) $key : '') . '/balance', [
            'balance_minor' => -45_000,
            'as_of' => '2026-03-01',
        ])
        ->assertCreated();

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.balance_minor', -45_000);
});

it('refuses a balance for an account belonging to another subject', function (): void {
    [$user, $subjectId] = walletSubject();
    [$otherUser, $otherSubjectId] = walletSubject();

    $this->actingAs($otherUser)->postJson("/api/v1/subjects/{$otherSubjectId}/wallet/accounts", [
        'display_name' => 'Cont strain',
        'kind' => AccountKind::BANK_CURRENT->value,
        'currency_code' => 'RON',
    ])->assertCreated();

    $key = FinancialAccount::query()->withoutGlobalScopes()->firstOrFail()->getKey();

    // Found through the tenant-scoped model, so another subject's account is simply absent — never
    // written as a foreign key on this subject's snapshot.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts/" . (is_scalar($key) ? (string) $key : '') . '/balance', [
            'balance_minor' => 1_000,
            'as_of' => '2026-03-01',
        ])
        ->assertNotFound();
});

it('records a transaction with its description and counterparty encrypted', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'ING curent',
        'kind' => AccountKind::BANK_CURRENT->value,
        'currency_code' => 'RON',
    ])->assertCreated();

    $key = FinancialAccount::query()->firstOrFail()->getKey();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => is_scalar($key) ? (string) $key : '',
        'amount_minor' => 250_000,
        'currency_code' => 'RON',
        'booked_at' => '2026-09-07',
        'description' => 'Chirie Apt 12',
        'counterparty' => 'Ionescu Maria',
    ])->assertCreated();

    $row = DB::table('financial_transactions')->first();

    // A counterparty is personal data about someone who never used this application.
    expect((string) $row?->description_ciphertext)->not->toContain('Chirie Apt 12')
        ->and((string) $row?->counterparty_ciphertext)->not->toContain('Ionescu');

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk()
        ->assertJsonPath('transactions.0.description', 'Chirie Apt 12')
        ->assertJsonPath('transactions.0.counterparty', 'Ionescu Maria')
        ->assertJsonPath('transactions.0.account_name', 'ING curent')
        ->assertJsonPath('transactions.0.amount_minor', 250_000);
});

it('refuses a zero-amount transaction', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'Cont',
        'kind' => AccountKind::BANK_CURRENT->value,
        'currency_code' => 'RON',
    ])->assertCreated();

    $key = FinancialAccount::query()->firstOrFail()->getKey();

    // Zero is never a real movement; accepting it would put a row in the table that the rental
    // matcher has to consider and can never match.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => is_scalar($key) ? (string) $key : '',
        'amount_minor' => 0,
        'currency_code' => 'RON',
        'booked_at' => '2026-09-07',
        'description' => 'Nimic',
    ])->assertStatus(422);
});

it('computes the whole interest schedule when the deposit is opened', function (): void {
    [$user, $subjectId] = walletSubject();

    // A RON deposit: no FX annotation is involved, so this isolates the interest calculation from
    // the BNR lookup a foreign-currency deposit would also need.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'BT',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 100_000_00,
        'currency_code' => 'RON',
        'opened_at' => '2026-01-01',
        'maturity_at' => '2026-12-31',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL_365',
        'annual_rate' => '6.00',
    ])->assertCreated();

    $key = DepositTerms::query()->firstOrFail()->getKey();
    $depositId = is_scalar($key) ? (string) $key : '';

    // No second call, and no button: opening the deposit is what produced these rows.
    expect(DB::table('deposit_interest_events')->where('account_id', $depositId)->count())
        ->toBeGreaterThan(0);

    $interest = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->json('accounts.0.deposit.interest_at_maturity_minor');

    // 100,000 RON at 6% for a full year on ACTUAL/365 is ~6,000 RON. Asserted as a range rather
    // than an exact figure: the precise total depends on the day-count and period boundaries the
    // calculator applies, which is its business to decide and not this test's to restate.
    expect(is_numeric($interest) ? (int) $interest : 0)
        ->toBeGreaterThan(5_500_00)
        ->toBeLessThan(6_500_00);
});

it('reports the full term interest, not only the part already elapsed', function (): void {
    [$user, $subjectId] = walletSubject();

    // Opened today, running for a year — so almost the entire schedule lies in the future, which is
    // exactly the case a "how much has it earned so far" figure would get wrong.
    $opened = CarbonImmutable::now('UTC')->toDateString();
    $maturity = CarbonImmutable::now('UTC')->addYear()->toDateString();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'ING',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 200_000_00,
        'currency_code' => 'RON',
        'opened_at' => $opened,
        'maturity_at' => $maturity,
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL_365',
        'annual_rate' => '5.00',
    ])->assertCreated();

    $key = DepositTerms::query()->firstOrFail()->getKey();
    $depositId = is_scalar($key) ? (string) $key : '';

    // The whole schedule exists, including periods ending after today — the figure a person opens a
    // deposit to find out is what it will pay by maturity, not what it has paid so far.
    $future = DB::table('deposit_interest_events')
        ->where('account_id', $depositId)
        ->whereDate('accrual_to', '>', $opened)
        ->count();

    expect($future)->toBeGreaterThan(0);

    // 200,000 RON at 5% for a full year is ~10,000 RON. A figure covering only the elapsed part of
    // the term would come out at essentially zero, which is the regression this pins.
    $interest = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->json('accounts.0.deposit.interest_at_maturity_minor');

    expect(is_numeric($interest) ? (int) $interest : 0)
        ->toBeGreaterThan(9_500_00)
        ->toBeLessThan(10_500_00);
});

it('sends the deposit form its term options and day-count default from configuration', function (): void {
    [$user, $subjectId] = walletSubject();

    config()->set('wallet.deposit.terms_months', [3, 6, 12]);

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('form_options.terms_months', [3, 6, 12])
        // The day-count convention is deliberately absent: it is applied server-side, never sent to
        // a screen for the screen to send back.
        ->assertJsonMissingPath('form_options.default_day_count');
});

it('applies the configured day-count convention to a deposit opened without one', function (): void {
    [$user, $subjectId] = walletSubject();

    config()->set('wallet.deposit.default_day_count', 'ACTUAL_360');

    // Exactly what the screen sends: no `day_count_convention` at all.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'BCR',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'RON',
        'opened_at' => '2026-01-01',
        'maturity_at' => '2026-07-01',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'annual_rate' => '4.00',
    ])->assertCreated();

    expect(DepositTerms::query()->firstOrFail()->day_count_convention)->toBe(DayCountConvention::ACTUAL_360);
});

it('still accepts an explicit convention, for a contract that states a different one', function (): void {
    [$user, $subjectId] = walletSubject();

    config()->set('wallet.deposit.default_day_count', 'ACTUAL_365');

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'BCR',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'RON',
        'opened_at' => '2026-01-01',
        'maturity_at' => '2026-07-01',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'THIRTY_360',
        'annual_rate' => '4.00',
    ])->assertCreated();

    expect(DepositTerms::query()->firstOrFail()->day_count_convention)->toBe(DayCountConvention::THIRTY_360);
});

it('rejects a day-count convention that is not a real one', function (): void {
    [$user, $subjectId] = walletSubject();

    // Optional does not mean unvalidated: a garbage value must fail loudly rather than fall through
    // to the configured default, which would silently apply a denominator nobody asked for.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'BCR',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'RON',
        'opened_at' => '2026-01-01',
        'maturity_at' => '2026-07-01',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL/365',
        'annual_rate' => '4.00',
    ])->assertStatus(422);
});

it('opens a foreign-currency deposit whose schedule runs past the last published FX rate', function (): void {
    [$user, $subjectId] = walletSubject();

    // No BNR rate exists for a future date, by definition, and `FxRateQuery` refuses to guess one.
    // The schedule still has to be computed: the gross interest in EUR is known now, only its RON
    // equivalent is not. Before this was handled, opening any foreign-currency deposit maturing in
    // the future failed outright.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'institution_name' => 'Raiffeisen',
        'display_name' => 'Depozit',
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'EUR',
        'opened_at' => CarbonImmutable::now('UTC')->toDateString(),
        'maturity_at' => CarbonImmutable::now('UTC')->addYear()->toDateString(),
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL_365',
        'annual_rate' => '2.00',
    ])->assertCreated();

    $key = DepositTerms::query()->firstOrFail()->getKey();
    $depositId = is_scalar($key) ? (string) $key : '';

    $event = DB::table('deposit_interest_events')->where('account_id', $depositId)->first();

    expect($event)->toBeObject();
    assert($event instanceof stdClass);

    // Gross interest in the deposit's own currency: known. RON equivalent: honestly absent, rather
    // than filled in from whichever rate happened to be handy.
    expect((int) $event->gross_interest_minor)->toBeGreaterThan(0);
    expect($event->ron_equivalent_minor)->toBeNull();
    expect($event->fx_source_document_version_id)->toBeNull();
});
