<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\DepositMaturityRemindersTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kern\Notifications\Notice;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Models\DepositTerms;
use Tests\TestCase;

/**
 * The daily maturity reminders: once per deposit and distance, to the taxpayer's owners, saying exactly
 * what matures — in the bell and on the device — while storing no amount.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
    config(['wallet.deposit.reminder_days' => [30, 7, 1, 0]]);
});

function member(string $subjectId, MembershipRole $role): User
{
    $user = User::factory()->create();
    SubjectMembership::create([
        'subject_id' => $subjectId,
        'user_id' => $user->id,
        'role' => $role,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return $user;
}

/** A deposit opened through the API, maturing `$days` from today. */
function depositMaturingIn(TestCase $case, User $owner, string $subjectId, int $days, string $bankName = 'Banca de Investiții și Dezvoltare'): string
{
    $today = CarbonImmutable::now()->startOfDay();
    $id = $case->actingAs($owner)->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", [
        'bank_id' => BankFactory::new()->createOne(['name' => $bankName])->id,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        'starts_at' => $today->subMonths(6)->toDateString(),
        'principal_minor' => 612345678,
        'term_months' => 12,
        'annual_rate' => '5.7500',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
    ])->assertCreated()->json('id');

    // The API takes a term, which never lands on an exact distance from today, and opens only deposits
    // maturing after it: the maturity under test is set by hand.
    DepositTerms::query()->withoutGlobalScopes()->whereKey($id)->update(['maturity_at' => $today->addDays($days)->toDateString()]);

    return is_string($id) ? $id : '';
}

it('tells the owners once per distance, and nobody else', function (): void {
    Notification::fake();
    $subject = TaxSubject::factory()->create();
    $owner = member($subject->id, MembershipRole::OWNER);
    $viewer = member($subject->id, MembershipRole::VIEWER);
    $deposit = depositMaturingIn($this, $owner, $subject->id, 7);
    depositMaturingIn($this, $owner, $subject->id, 3);

    expect(Artisan::call('kern:wallet:remind-maturities'))->toBe(0)
        ->and(Artisan::call('kern:wallet:remind-maturities'))->toBe(0);

    Notification::assertSentToTimes($owner, Notice::class, 1);
    Notification::assertNotSentTo($viewer, Notice::class);
    expect(DB::table('deposit_maturity_reminders')->where('account_id', $deposit)->value('days_before'))->toBe(7)
        ->and(DB::table('deposit_maturity_reminders')->where('tax_subject_id', $subject->id)->count())->toBe(1);
});

it('still says "today" of a deposit closed on its maturity, and nothing of one closed early', function (): void {
    Notification::fake();
    $subject = TaxSubject::factory()->create();
    $owner = member($subject->id, MembershipRole::OWNER);
    $matured = depositMaturingIn($this, $owner, $subject->id, 0);
    $broken = depositMaturingIn($this, $owner, $subject->id, 0);
    $today = CarbonImmutable::now()->toDateString();
    DepositTerms::query()->withoutGlobalScopes()->whereKey($matured)->update(['closed_at' => $today]);
    DepositTerms::query()->withoutGlobalScopes()->whereKey($broken)->update(['closed_at' => CarbonImmutable::now()->subDays(20)->toDateString()]);

    expect(Artisan::call('kern:wallet:remind-maturities'))->toBe(0)
        ->and(DB::table('deposit_maturity_reminders')->where('tax_subject_id', $subject->id)->pluck('account_id')->all())->toBe([$matured]);
});

it('says exactly what matures, in the bell and on the device alike, while storing no amount', function (): void {
    $subject = TaxSubject::factory()->create();
    $owner = member($subject->id, MembershipRole::OWNER);
    $id = depositMaturingIn($this, $owner, $subject->id, 7);

    expect(Artisan::call('kern:wallet:remind-maturities'))->toBe(0);

    $stored = DB::table('notifications')->where('notifiable_id', $owner->id)->sole();
    expect($stored->type)->toBe('deposit-maturity')
        ->and($stored->data)->not->toContain('612345678')
        ->and($stored->data)->not->toContain('Investiții');

    $bell = $this->actingAs($owner)->getJson('/api/v1/notifications?locale=ro')
        ->assertOk()
        ->assertJsonPath('notifications.0.title', 'Un depozit ajunge la scadență în 7 zile')
        ->assertJsonPath('notifications.0.url', '/wallet/accounts?subject=' . $subject->id);

    expect($bell->json('notifications.0.body'))
        ->toStartWith('Banca de Investiții și Dezvoltare: 6.123.456,78')
        ->toContain('RON');

    $this->actingAs($owner)->getJson('/api/v1/notifications?locale=en')
        ->assertJsonPath('notifications.0.title', 'A deposit matures in 7 days');

    expect(DepositTerms::query()->withoutGlobalScopes()->whereKey($id)->exists())->toBeTrue();
});

it('stops showing a deposit to someone who no longer acts for its taxpayer', function (): void {
    $subject = TaxSubject::factory()->create();
    $owner = member($subject->id, MembershipRole::OWNER);
    depositMaturingIn($this, $owner, $subject->id, 7);
    Artisan::call('kern:wallet:remind-maturities');

    SubjectMembership::query()->where('user_id', $owner->id)->update(['revoked_at' => now('UTC')]);

    $this->actingAs($owner)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'notifications');
});

it('costs the bell the same few queries for one deposit or many', function (): void {
    $queriesFor = function (int $deposits): int {
        $subject = TaxSubject::factory()->create();
        $owner = member($subject->id, MembershipRole::OWNER);

        for ($i = 0; $i < $deposits; $i++) {
            depositMaturingIn($this, $owner, $subject->id, 7);
        }

        Artisan::call('kern:wallet:remind-maturities');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->getJson('/api/v1/notifications')->assertJsonCount($deposits, 'notifications');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(3))->toBe($queriesFor(1));
});
