<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\HoldingNoticesTest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Enums\PayoutKind;
use Kern\Investments\Enums\PayoutSource;
use Kern\Investments\Enums\PayoutStatus;
use Kern\Investments\Enums\Programme;
use Kern\Investments\Events\IssueOnOffer;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\InstrumentPayout;
use Kern\Notifications\Notice;
use Kern\Notifications\Services\Preferences;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Tenancy\TenantScope;
use Kern\TaxRomania\Notices\AnnounceIssueOnOffer;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\HeldAt;
use Kern\TaxRomania\Wallet\Enums\RecordSource;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\InvestmentLot;

/**
 * The two notices holdings bring (ADR 0025 §8, §9): a new issue on offer, to whoever has its switch on;
 * and the reminder before a coupon or a repayment, through the deposits' maturity reminders. Made-up
 * instruments; nothing personal is stored in a notice.
 */
function fidelis(InstrumentStatus $status): FinancialInstrument
{
    return FinancialInstrument::query()->create([
        'kind' => InstrumentKind::GOVERNMENT_BOND,
        'programme' => Programme::FIDELIS,
        'symbol' => 'R2899T',
        'isin' => 'ROTEST000001',
        'name' => 'FIDELIS R2899T',
        'issuer_name' => 'Emitent de test',
        'currency' => 'RON',
        'face_value' => '100',
        'rate' => '7.25',
        'issue_date' => '2026-12-01',
        'maturity_date' => '2031-12-01',
        'market' => Market::BVB,
        'status' => $status,
    ]);
}

it('tells of a new issue on offer everyone whose switch is on, and nobody who turned it off', function (): void {
    $told = User::factory()->create();
    $declined = User::factory()->create();
    app(Preferences::class)->set($declined, 'issue-on-offer', false);
    $issue = fidelis(InstrumentStatus::ON_OFFER);

    expect(Event::hasListeners(IssueOnOffer::class))->toBeTrue();

    app(AnnounceIssueOnOffer::class)->handle(new IssueOnOffer($issue->id));

    expect(DB::table('notifications')->where('notifiable_id', $declined->id)->exists())->toBeFalse()
        ->and(json_decode((string) DB::table('notifications')->where('notifiable_id', $told->id)->value('data'), true))->toBe(['instrument_id' => $issue->id]);

    $this->actingAs($told)->getJson('/api/v1/notifications?locale=ro')
        ->assertOk()
        ->assertJsonPath('notifications.0.title', 'În subscriere: FIDELIS R2899T');
    $this->actingAs($told)->getJson('/api/v1/notifications?locale=en')
        ->assertJsonPath('notifications.0.title', 'On offer: FIDELIS R2899T');

    // Once the subscription is over, the news is too.
    $issue->update(['status' => InstrumentStatus::ACTIVE]);
    $this->actingAs($told)->getJson('/api/v1/notifications?locale=en')->assertJsonCount(0, 'notifications');
});

it('reminds the owners once before a coupon, and not of a lot already sold', function (): void {
    Notification::fake();
    config(['wallet.deposit.reminder_days' => [7]]);
    $owner = User::factory()->create();
    $subject = TaxSubject::factory()->create();
    SubjectMembership::create(['subject_id' => $subject->id, 'user_id' => $owner->id, 'role' => MembershipRole::OWNER, 'permissions_json' => [], 'valid_from' => now('UTC')]);
    $bond = fidelis(InstrumentStatus::ACTIVE);
    InstrumentPayout::query()->create([
        'instrument_id' => $bond->id,
        'kind' => PayoutKind::COUPON,
        'payment_date' => CarbonImmutable::now()->addDays(7)->toDateString(),
        'amount_per_unit' => '7.25',
        'status' => PayoutStatus::ANNOUNCED,
        'source' => PayoutSource::ORDER,
    ]);
    $accounts = [];

    foreach (['open' => null, 'sold' => '2026-03-01'] as $name => $soldOn) {
        $accounts[$name] = FinancialAccount::create([
            'tax_subject_id' => $subject->id,
            'kind' => AccountKind::INVESTMENTS,
            'display_name_ciphertext' => "Cont {$name}",
            'source' => RecordSource::MANUAL,
        ]);
        InvestmentLot::query()->withoutGlobalScope(TenantScope::class)->create([
            'tax_subject_id' => $subject->id,
            'financial_account_id' => $accounts[$name]->id,
            'instrument_id' => $bond->id,
            'units' => '10',
            'price_per_unit' => '100',
            'bought_on' => '2026-01-10',
            'fees_minor' => 0,
            'currency' => 'RON',
            'held_at' => HeldAt::BANK,
            'sold_on' => $soldOn,
            'sold_price_per_unit' => $soldOn === null ? null : '100',
            'sale_fees_minor' => $soldOn === null ? null : 0,
        ]);
    }

    expect(Artisan::call('kern:wallet:remind-maturities'))->toBe(0)
        ->and(Artisan::call('kern:wallet:remind-maturities'))->toBe(0);

    Notification::assertSentToTimes($owner, Notice::class, 1);
    expect(DB::table('investment_payout_reminders')->pluck('financial_account_id')->all())->toBe([$accounts['open']->id]);
});
