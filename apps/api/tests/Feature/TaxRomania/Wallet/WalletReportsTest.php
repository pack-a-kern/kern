<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\WalletReportsTest;

use Illuminate\Support\Facades\DB;
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
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Tests\TestCase;

/*
 * Postgres only: WalletReports groups by month with `to_char`, which is Postgres's date formatter.
 *
 * Skipped rather than rewritten, because the application is Postgres and should stay written for it —
 * `make check-php-pg` is the lane that runs this. See `Tests\Feature\Audit\AuditChainServiceTest`
 * for the same guard on the same grounds.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs a real Postgres connection — run `make check-php-pg`.');
    }
});
/**
 * A year read three ways: by tag, by bank, by category.
 *
 * The property that matters most here is the one a report is most tempted to break: figures are
 * grouped by currency and never added across them, because 100 EUR and 100 RON are not 200 of
 * anything.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string, 2: string} */
function reportingSubject(): array
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

/** @param array<string, mixed> $extra */
function spend(TestCase $case, User $user, string $subjectId, string $accountId, int $amount, array $extra = []): void
{
    $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", array_merge([
        'account_id' => $accountId,
        'amount_minor' => $amount,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-05',
        'description' => 'Plată',
    ], $extra))->assertCreated();
}

it('adds up spending per tag, as a positive figure', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, ['tags' => ['alimente']]);
    spend($this, $user, $subjectId, $accountId, -8_000, ['tags' => ['alimente']]);
    spend($this, $user, $subjectId, $accountId, -20_000, ['tags' => ['combustibil']]);
    // Money in carrying a tag is not spending, and must not join the total.
    spend($this, $user, $subjectId, $accountId, 50_000, ['tags' => ['alimente'], 'category_code' => 'OTHER_INCOME']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('by_tag');
    $byTag = collect($rows)->keyBy('name');

    /** @var array<string, mixed> $alimente */
    $alimente = $byTag->get('alimente');
    /** @var array<string, mixed> $combustibil */
    $combustibil = $byTag->get('combustibil');

    expect($alimente['total_minor'])->toBe(20_000)
        ->and($alimente['currency_code'])->toBe('RON')
        ->and($combustibil['total_minor'])->toBe(20_000);
});

it('never adds two currencies into one figure', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();

    spend($this, $user, $subjectId, $accountId, -10_000, ['tags' => ['calatorii']]);
    spend($this, $user, $subjectId, $accountId, -10_000, ['tags' => ['calatorii'], 'currency_code' => 'EUR']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('by_tag');

    // One tag, two rows: the same label in two currencies is two answers, not one sum.
    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('currency_code')->sort()->values()->all())
        ->toBe(['EUR', 'RON']);
});

it('separates what came in from what went out at each bank', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();
    $bank = BankFactory::new()->createOne();

    spend($this, $user, $subjectId, $accountId, -30_000, ['bank_id' => $bank->id]);
    spend($this, $user, $subjectId, $accountId, 70_000, ['bank_id' => $bank->id, 'category_code' => 'OTHER_INCOME']);
    // No bank named: gathered on its own rather than dropped, or the totals would disagree with the
    // transactions list.
    spend($this, $user, $subjectId, $accountId, -5_000);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('by_bank');
    $byBank = collect($rows)->keyBy(static fn (array $row): string => is_string($row['bank_id'] ?? null) ? $row['bank_id'] : 'none');

    /** @var array<string, mixed> $atBank */
    $atBank = $byBank->get((string) $bank->id);
    /** @var array<string, mixed> $unnamed */
    $unnamed = $byBank->get('none');

    expect($atBank['in_minor'])->toBe(70_000)
        ->and($atBank['out_minor'])->toBe(30_000)
        ->and($unnamed['out_minor'])->toBe(5_000);
});

it('totals each category from the splits', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, ['category_code' => 'EXPENSE']);
    spend($this, $user, $subjectId, $accountId, -8_000, ['category_code' => 'EXPENSE']);
    spend($this, $user, $subjectId, $accountId, 45_000, ['category_code' => 'DIVIDENDS_INTEREST']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('by_category');
    $byCategory = collect($rows)->keyBy('category_code');

    /** @var array<string, mixed> $expense */
    $expense = $byCategory->get('EXPENSE');
    /** @var array<string, mixed> $interest */
    $interest = $byCategory->get('DIVIDENDS_INTEREST');

    expect($expense['total_minor'])->toBe(-20_000)
        ->and($interest['total_minor'])->toBe(45_000);
});

it('counts only the period asked for', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();

    spend($this, $user, $subjectId, $accountId, -12_000, ['tags' => ['alimente'], 'booked_at' => '2026-03-05']);
    spend($this, $user, $subjectId, $accountId, -99_000, ['tags' => ['alimente'], 'booked_at' => '2025-11-30']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    expect($response->json('by_tag.0.total_minor'))->toBe(12_000);
});

it('leaves a transfer out of the totals whoever recorded it', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();

    // A deposit maturing and being re-constituted, as an imported statement writes it: the same
    // money out and back, which is neither income nor spending. Before this, only the rows this
    // application wrote itself were excluded, so one imported year of a rolled-over deposit read as
    // 200,719 in and 200,719 out — a year that earned 97.69 in interest.
    spend($this, $user, $subjectId, $accountId, 10_028_600, ['category_code' => 'TRANSFER']);
    spend($this, $user, $subjectId, $accountId, -10_033_616, ['category_code' => 'TRANSFER']);

    // What the year actually was.
    spend($this, $user, $subjectId, $accountId, 5_536, ['category_code' => 'DIVIDENDS_INTEREST']);
    spend($this, $user, $subjectId, $accountId, -554, ['category_code' => 'EXPENSE']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?year=2026")
        ->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('totals');
    /** @var array<string, mixed> $totals */
    $totals = collect($rows)->firstWhere('currency_code', 'RON');

    expect($totals['in_minor'])->toBe(5_536)
        ->and($totals['out_minor'])->toBe(554)
        ->and($totals['count'])->toBe(2);
});

it('measures the ten largest parties against what was paid to every one of them', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();

    foreach (range(1, 12) as $index) {
        spend($this, $user, $subjectId, $accountId, -1_000 * $index, ['counterparty' => "Example Shop {$index}"]);
    }
    // Paid to nobody named: not a party's, and not in their whole.
    spend($this, $user, $subjectId, $accountId, -50_000);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    expect($response->json('by_counterparty'))->toHaveCount(10)
        ->and($response->json('counterparty_totals'))->toBe([['currency_code' => 'RON', 'total_minor' => 78_000]])
        // Ranked by the server, each party's share of every party's whole and its bar against the largest.
        ->and($response->json('breakdowns.0.merchant.total_minor'))->toBe(78_000)
        ->and($response->json('breakdowns.0.merchant.rows.0.name'))->toBe('Example Shop 12')
        ->and($response->json('breakdowns.0.merchant.rows.0.share'))->toBe(bcdiv('12000', '78000', 4))
        ->and($response->json('breakdowns.0.merchant.rows.0.bar'))->toBe('1.0000')
        ->and($response->json('breakdowns.0.merchant.rows.1.bar'))->toBe(bcdiv('11000', '12000', 4));
});

it('measures the period for the screen: the net, the change, what was kept and how much is sorted', function (): void {
    [$user, $subjectId, $accountId] = reportingSubject();

    // The year before: 400 in, 100 out. This year: 600 in, 150 out, in two months — the larger in March.
    spend($this, $user, $subjectId, $accountId, 40_000, ['booked_at' => '2025-05-01', 'category_code' => 'OTHER_INCOME']);
    spend($this, $user, $subjectId, $accountId, -10_000, ['booked_at' => '2025-05-02']);
    spend($this, $user, $subjectId, $accountId, 60_000, ['booked_at' => '2026-03-01', 'category_code' => 'OTHER_INCOME']);
    spend($this, $user, $subjectId, $accountId, -10_000, ['booked_at' => '2026-03-02']);
    spend($this, $user, $subjectId, $accountId, -5_000, ['booked_at' => '2026-04-02']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    expect($response->json('totals.0'))->toMatchArray([
        'net_minor' => 45_000,
        'in_change' => '0.5000',
        'out_change' => '0.5000',
        'kept_share' => '0.7500',
        'activity_share' => '1.0000',
    ])
        ->and(array_column((array) $response->json('cash_flow'), 'out_bar'))->toBe(['1.0000', '0.5000'])
        ->and($response->json('attention.classified_count'))->toBe(3)
        ->and($response->json('attention.total_count'))->toBe(3)
        ->and($response->json('attention.classified_share'))->toBeString();
});
