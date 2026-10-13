<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\ExportWalletReportsTest;

use Illuminate\Support\Facades\DB;
use Kern\Audit\Models\AuditEvent;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Tests\TestCase;

/*
 * Postgres only, for the same reason `WalletReportsTest` is: the report groups by month with
 * `to_char`. Run through `make check-php-pg`.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs a real Postgres connection — run `make check-php-pg`.');
    }

    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string, 2: string} */
function exportingSubject(): array
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

function exportPart(TestCase $case, User $user, string $subjectId, string $part): string
{
    $body = $case->actingAs($user)
        ->get("/api/v1/subjects/{$subjectId}/wallet/reports/export?from=2026-01-01&to=2026-12-31&part={$part}")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->getContent();

    return $body === false ? '' : $body;
}

it('exports the year by month as figures a spreadsheet can add up', function (): void {
    [$user, $subjectId, $accountId] = exportingSubject();

    record($this, $user, $subjectId, $accountId, -12_345);
    record($this, $user, $subjectId, $accountId, 100_000, ['category_code' => 'OTHER_INCOME']);

    $body = exportPart($this, $user, $subjectId, 'months');

    expect($body)
        ->toContain('month,currency,money_in,money_out,net,transactions,top_category')
        // Plain dot decimal, no grouping, no currency in the cell — the column beside it says RON.
        ->toContain('2026-03,RON,1000.00,123.45,876.55')
        // A BOM, or Excel on Windows mangles every diacritic in a counterparty's name.
        ->toStartWith("\u{FEFF}");
});

it('names the dimension each breakdown row was ranked by', function (): void {
    [$user, $subjectId, $accountId] = exportingSubject();

    record($this, $user, $subjectId, $accountId, -20_000, ['tags' => ['alimente']]);

    $body = exportPart($this, $user, $subjectId, 'breakdown');

    expect($body)
        ->toContain('dimension,label,currency,total,transactions')
        ->toContain('tag,alimente,RON,200.00');
});

it('carries every currency, not only the one the screen is showing', function (): void {
    // A file that silently dropped the EUR rows could not be noticed afterwards. The two are still
    // never added together — each row names its own currency.
    [$user, $subjectId, $accountId] = exportingSubject();

    record($this, $user, $subjectId, $accountId, -10_000);
    record($this, $user, $subjectId, $accountId, -10_000, ['currency_code' => 'EUR']);

    $body = exportPart($this, $user, $subjectId, 'movement');

    expect($body)
        ->toContain('dimension,source,kind,currency,money_in,money_out')
        ->toContain('currency,RON,,RON,0.00,100.00')
        ->toContain('currency,EUR,,EUR,0.00,100.00');
});

it('reaches the transaction behind each of the biggest movements', function (): void {
    [$user, $subjectId, $accountId] = exportingSubject();

    record($this, $user, $subjectId, $accountId, -90_000, ['counterparty' => 'Dedeman']);

    $body = exportPart($this, $user, $subjectId, 'largest');

    expect($body)
        ->toContain('booked_at,counterparty,description,currency,amount,transaction_id')
        ->toContain('Dedeman')
        ->toContain('-900.00');
});

it('refuses a part it does not publish', function (): void {
    [$user, $subjectId] = exportingSubject();

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/reports/export?part=everything")
        ->assertStatus(422);
});

it('records every download in the audit chain', function (): void {
    [$user, $subjectId, $accountId] = exportingSubject();

    record($this, $user, $subjectId, $accountId, -5_000);
    exportPart($this, $user, $subjectId, 'largest');

    $event = AuditEvent::query()
        ->where('subject_id', $subjectId)
        ->where('event_type', 'wallet.reports.exported')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->resource_type)->toBe('wallet_report');
});
