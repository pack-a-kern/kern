<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\WalletReportsTest;

use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\TestCase;

/**
 * A year read three ways: by tag, by bank, by category.
 *
 * The property that matters most here is the one a report is most tempted to break: figures are
 * grouped by currency and never added across them, because 100 EUR and 100 RON are not 200 of
 * anything.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
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
    spend($this, $user, $subjectId, $accountId, 45_000, ['category_code' => 'DIVIDENDS']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports?from=2026-01-01&to=2026-12-31")
        ->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = $response->json('by_category');
    $byCategory = collect($rows)->keyBy('category_code');

    /** @var array<string, mixed> $expense */
    $expense = $byCategory->get('EXPENSE');
    /** @var array<string, mixed> $dividends */
    $dividends = $byCategory->get('DIVIDENDS');

    expect($expense['total_minor'])->toBe(-20_000)
        ->and($dividends['total_minor'])->toBe(45_000);
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
