<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\BankActivityTest;

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

/**
 * A rundown of the banks a taxpayer's transactions name: counts, span, accounts and per-currency flow.
 *
 * The property it guards is the one every per-currency figure here must keep — money is never added
 * across currencies — alongside the two a rundown is for: it counts every bank the ledger names, and
 * it counts only this taxpayer's.
 */
beforeEach(function (): void {
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string} */
function bankingSubject(): array
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

function account(string $subjectId): string
{
    return FinancialAccount::create([
        'tax_subject_id' => $subjectId,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ])->id;
}

/** @param array<string, mixed> $extra */
function record(TestCase $case, User $user, string $subjectId, string $accountId, int $amount, array $extra = []): void
{
    $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", array_merge([
        'account_id' => $accountId,
        'amount_minor' => $amount,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-05',
        'description' => 'Plată',
    ], $extra))->assertCreated();
}

it('counts a bank, its span, its accounts and its flow', function (): void {
    [$user, $subjectId] = bankingSubject();
    $accountA = account($subjectId);
    $accountB = account($subjectId);
    $bank = BankFactory::new()->createOne();

    record($this, $user, $subjectId, $accountA, -30_000, ['bank_id' => $bank->id, 'booked_at' => '2026-02-01']);
    record($this, $user, $subjectId, $accountA, 70_000, ['bank_id' => $bank->id, 'booked_at' => '2026-05-20', 'category_code' => 'OTHER_INCOME']);
    record($this, $user, $subjectId, $accountB, -5_000, ['bank_id' => $bank->id, 'booked_at' => '2026-04-10']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/banks/activity")
        ->assertOk();

    /** @var list<array<string, mixed>> $banks */
    $banks = $response->json('banks');
    /** @var array<string, mixed> $row */
    $row = collect($banks)->firstWhere('bank_id', $bank->id);

    expect($row['name'])->toBe($bank->name)
        ->and($row['transaction_count'])->toBe(3)
        ->and($row['account_count'])->toBe(2)
        ->and($row['first_booked_at'])->toBe('2026-02-01')
        ->and($row['last_booked_at'])->toBe('2026-05-20')
        ->and($row['flows'])->toBe([
            ['currency_code' => 'RON', 'in_minor' => 70_000, 'out_minor' => 35_000, 'count' => 3],
        ])
        ->and($response->json('totals.bank_count'))->toBe(1)
        ->and($response->json('totals.transaction_count'))->toBe(3)
        ->and($response->json('totals.identified_count'))->toBe(3)
        ->and($response->json('totals.unidentified_count'))->toBe(0);
});

it('gathers transactions with no bank under a null row', function (): void {
    [$user, $subjectId] = bankingSubject();
    $accountId = account($subjectId);
    $bank = BankFactory::new()->createOne();

    record($this, $user, $subjectId, $accountId, -10_000, ['bank_id' => $bank->id]);
    record($this, $user, $subjectId, $accountId, -4_000);
    record($this, $user, $subjectId, $accountId, -6_000);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/banks/activity")
        ->assertOk();

    /** @var list<array<string, mixed>> $banks */
    $banks = $response->json('banks');
    /** @var array<string, mixed> $unidentified */
    $unidentified = collect($banks)->firstWhere('bank_id', null);

    expect($unidentified['name'])->toBeNull()
        ->and($unidentified['transaction_count'])->toBe(2)
        // The bucket for the unknown bank sorts to the end, never ahead of a named one.
        ->and($response->json('banks.1.bank_id'))->toBeNull()
        ->and($response->json('totals.bank_count'))->toBe(1)
        ->and($response->json('totals.identified_count'))->toBe(1)
        ->and($response->json('totals.unidentified_count'))->toBe(2);
});

it('never adds two currencies into one flow', function (): void {
    [$user, $subjectId] = bankingSubject();
    $accountId = account($subjectId);
    $bank = BankFactory::new()->createOne();

    record($this, $user, $subjectId, $accountId, -10_000, ['bank_id' => $bank->id]);
    record($this, $user, $subjectId, $accountId, -10_000, ['bank_id' => $bank->id, 'currency_code' => 'EUR']);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/banks/activity")
        ->assertOk();

    /** @var list<array<string, mixed>> $banks */
    $banks = $response->json('banks');
    /** @var array<string, mixed> $row */
    $row = collect($banks)->firstWhere('bank_id', $bank->id);

    expect($row['flows'])->toBe([
        ['currency_code' => 'EUR', 'in_minor' => 0, 'out_minor' => 10_000, 'count' => 1],
        ['currency_code' => 'RON', 'in_minor' => 0, 'out_minor' => 10_000, 'count' => 1],
    ]);
});

it('shows only the signed-in taxpayer their own banks', function (): void {
    [$user, $subjectId] = bankingSubject();
    [$other, $otherSubjectId] = bankingSubject();
    $mine = account($subjectId);
    $theirs = account($otherSubjectId);
    $myBank = BankFactory::new()->createOne();
    $theirBank = BankFactory::new()->createOne();

    record($this, $user, $subjectId, $mine, -10_000, ['bank_id' => $myBank->id]);
    record($this, $other, $otherSubjectId, $theirs, -99_000, ['bank_id' => $theirBank->id]);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/banks/activity")
        ->assertOk();

    /** @var list<array<string, mixed>> $banks */
    $banks = $response->json('banks');
    $ids = collect($banks)->pluck('bank_id')->all();

    expect($ids)->toBe([$myBank->id])
        ->and($response->json('totals.transaction_count'))->toBe(1);
});
