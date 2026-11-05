<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\DepositMaturityRemindersTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Notifications\Channels\WebPushChannel;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Models\DepositTerms;
use Kern\TaxRomania\Wallet\Notifications\DepositMaturityNotice;
use Tests\TestCase;

/**
 * The daily maturity reminders: once per deposit and distance, to the taxpayer's owners, saying exactly
 * what matures — on the device — while the bell keeps no amount.
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
        'maturity_at' => $today->addDays(max($days, 1))->toDateString(),
        'annual_rate' => '5.7500',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
    ])->assertCreated()->json('id');

    if ($days === 0) {
        // The API opens only future deposits; one maturing today is moved there by hand.
        DepositTerms::query()->withoutGlobalScopes()->whereKey($id)->update(['maturity_at' => $today->toDateString()]);
    }

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

    Notification::assertSentToTimes($owner, DepositMaturityNotice::class, 1);
    Notification::assertNotSentTo($viewer, DepositMaturityNotice::class);
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

it('says exactly what matures on the device, and keeps no amount in the bell', function (): void {
    $subject = TaxSubject::factory()->create();
    $owner = member($subject->id, MembershipRole::OWNER);
    $id = depositMaturingIn($this, $owner, $subject->id, 7);
    $terms = DepositTerms::query()->withoutGlobalScopes()->findOrFail($id);

    $notice = new DepositMaturityNotice($terms, 7, 'Banca de Investiții și Dezvoltare');
    $push = $notice->toPush($owner, 'ro');

    expect($notice->via($owner))->toContain(WebPushChannel::class)
        ->and($push->title)->toBe('Un depozit ajunge la scadență în 7 zile')
        ->and($push->body)->toContain('Banca de Investiții și Dezvoltare')
        ->and($push->body)->toContain('6.123.456,78')
        ->and($notice->toPush($owner, 'en')->title)->toBe('A deposit matures in 7 days')
        ->and(json_encode($notice->toArray($owner)))->not->toContain('612345678')
        ->and($notice->toArray($owner))->toBe([
            'subject_id' => $subject->id,
            'account_id' => $id,
            'days_before' => 7,
            'maturity_at' => $terms->maturity_at->toDateString(),
        ]);
});
