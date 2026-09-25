<?php

declare(strict_types=1);

use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use Carbon\CarbonImmutable;
use Database\Factories\BankFactory;
use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;

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

    // Cross-tenant access is the property every Wallet model's `ScopedToTenant` exists to
    // guarantee; asserted at the HTTP boundary because that is where an attacker would try it.
    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$other->getKey()}/wallet/accounts")
        ->assertForbidden();
});

it('opens a source through the action, encrypting its name at rest', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
            'display_name' => 'ING curent',
            'kind' => AccountKind::OTHER->value,
            'currency_code' => 'RON',
        ])
        ->assertCreated();

    // The raw row must not contain the name in the clear — the whole point of the DEK. The IBAN it
    // used to hold is gone: nothing matched an import by it and nothing reconciled with it, so a
    // full encryption-plus-blind-index apparatus was being maintained for a `•••• 1234` suffix.
    $row = DB::table('financial_accounts')->where('tax_subject_id', $subjectId)->first();

    expect($row)->not->toBeNull()
        ->and((string) $row?->display_name_ciphertext)->not->toContain('ING curent');

    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()
        ->assertJsonPath('accounts.0.display_name', 'ING curent');
});

it('totals balances per currency and never sums across them', function (): void {
    [$user, $subjectId] = walletSubject();

    foreach ([['RON', 70_000_00], ['EUR', 1_200_00], ['RON', 2_480_00]] as $index => [$currency, $amount]) {
        $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
            'display_name' => "Sursa {$index}",
            'kind' => AccountKind::OTHER->value,
            'currency_code' => $currency,
        ])->assertCreated();
    }

    /** @var list<array{id: string, currency_code: string|null}> $accounts */
    $accounts = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->json('accounts');

    foreach ([['RON', 70_000_00], ['EUR', 1_200_00], ['RON', 2_480_00]] as $index => [$currency, $amount]) {
        $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
            'account_id' => $accounts[$index]['id'],
            'amount_minor' => $amount,
            'currency_code' => $currency,
            'booked_at' => '2026-01-01',
            'description' => 'Intrare',
        ])->assertCreated();
    }

    // Two currencies stay two figures. Adding them would produce a number that is not any quantity
    // of anything — which is exactly what the previous single-balance-per-account version did.
    $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/overview")->assertOk()
        ->assertJsonPath('totals.holdings.other.amounts.RON', 72_480_00)
        ->assertJsonPath('totals.holdings.other.amounts.EUR', 1_200_00);
});

it('rejects a deposit whose maturity precedes its opening', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 50_000_00,
        'currency_code' => 'RON',
        // Both in the future, so the only thing wrong is their order — a maturity in the past is
        // refused by a different rule, and would let this test pass without testing this one.
        'starts_at' => CarbonImmutable::now('UTC')->addMonths(2)->toDateString(),
        'maturity_at' => CarbonImmutable::now('UTC')->addMonth()->toDateString(),
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL_365',
        'annual_rate' => '5.40',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['maturity_at']);
});

it('opens a deposit and reports its current rate and days to maturity', function (): void {
    [$user, $subjectId] = walletSubject();
    $opened = CarbonImmutable::now('UTC')->subMonth()->toDateString();
    $maturity = CarbonImmutable::now('UTC')->addMonths(5)->toDateString();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 70_000_00,
        'currency_code' => 'RON',
        'starts_at' => $opened,
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

it('records a transaction with its description and counterparty encrypted', function (): void {
    [$user, $subjectId] = walletSubject();

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'display_name' => 'ING curent',
        'kind' => AccountKind::GENERAL->value,
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
        'kind' => AccountKind::GENERAL->value,
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
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 100_000_00,
        'currency_code' => 'RON',
        // A year from today rather than a calendar year: a deposit must mature after today.
        'starts_at' => CarbonImmutable::now('UTC')->toDateString(),
        'maturity_at' => CarbonImmutable::now('UTC')->addYear()->toDateString(),
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
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 200_000_00,
        'currency_code' => 'RON',
        'starts_at' => $opened,
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
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'RON',
        // Relative to today: a deposit must mature after today, and a fixed date would turn this
        // test into one about the calendar on the day the suite runs.
        'starts_at' => CarbonImmutable::now('UTC')->toDateString(),
        'maturity_at' => CarbonImmutable::now('UTC')->addMonths(6)->toDateString(),
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
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'RON',
        // Relative to today: a deposit must mature after today, and a fixed date would turn this
        // test into one about the calendar on the day the suite runs.
        'starts_at' => CarbonImmutable::now('UTC')->toDateString(),
        'maturity_at' => CarbonImmutable::now('UTC')->addMonths(6)->toDateString(),
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
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'RON',
        // Relative to today: a deposit must mature after today, and a fixed date would turn this
        // test into one about the calendar on the day the suite runs.
        'starts_at' => CarbonImmutable::now('UTC')->toDateString(),
        'maturity_at' => CarbonImmutable::now('UTC')->addMonths(6)->toDateString(),
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        'day_count_convention' => 'ACTUAL/365',
        'annual_rate' => '4.00',
    ])->assertStatus(422)
        // Named, so a request refused for some other reason cannot pass this test by accident.
        ->assertJsonValidationErrors(['day_count_convention']);
});

it('opens a foreign-currency deposit whose schedule runs past the last published FX rate', function (): void {
    [$user, $subjectId] = walletSubject();

    // No BNR rate exists for a future date, by definition, and `FxRateQuery` refuses to guess one.
    // The schedule still has to be computed: the gross interest in EUR is known now, only its RON
    // equivalent is not. Before this was handled, opening any foreign-currency deposit maturing in
    // the future failed outright.
    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne()->id,
        'kind' => 'TERM_DEPOSIT',
        'principal_minor' => 10_000_00,
        'currency_code' => 'EUR',
        'starts_at' => '2026-01-01',
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
