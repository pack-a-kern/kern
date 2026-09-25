<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\CrossTenantIsolationTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\DayCountConvention;
use App\Domain\Wallet\Enums\InterestMethod;
use App\Domain\Wallet\Enums\PaymentFrequency;
use App\Domain\Wallet\Enums\RateType;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\DepositInterestEvent;
use App\Domain\Wallet\Models\DepositRatePeriod;
use App\Domain\Wallet\Models\DepositTerms;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use App\Domain\Wallet\Models\TransactionSplit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Tenancy\TenantContext;

/**
 * `docs/AGENTS.md` §21.4 / `BLUEPRINT.md` §5.3: a user authorized for one `TaxSubject` must never
 * reach another subject's row, through any of the seven tables this module owns — every one uses
 * `Kern\Platform\Tenancy\ScopedToTenant`, so this test exercises that mechanism directly
 * against each model rather than trusting that "it uses the same trait" is enough on its own.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

/**
 * @return array{0: User, 1: TaxSubject}
 */
function ownerAndSubject(): array
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

    return [$user, $subject];
}

function activateAs(User $user, TaxSubject $subject): void
{
    $context = app(TenantContext::class);
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);
}

/**
 * One row-factory closure per model: given a `tax_subject_id`, create and return one row.
 * Deliberately hand-rolled rather than Eloquent factories for every table, so this test is
 * self-contained and does not depend on factory `definition()` choices changing meaning.
 *
 * @return array<string, callable(string): Model>
 */
function walletRowFactories(): array
{
    return [
        FinancialAccount::class => static function (string $subjectId): FinancialAccount {
            return FinancialAccount::create([
                'tax_subject_id' => $subjectId,
                'kind' => AccountKind::GENERAL,
                'display_name_ciphertext' => 'Cross-tenant test account',
                'currency_code' => 'RON',
                'source' => RecordSource::MANUAL,
            ]);
        },
        FinancialTransaction::class => static function (string $subjectId): FinancialTransaction {
            $account = FinancialAccount::create([
                'tax_subject_id' => $subjectId,
                'kind' => AccountKind::GENERAL,
                'display_name_ciphertext' => 'Transaction owner account',
                'currency_code' => 'RON',
                'source' => RecordSource::MANUAL,
            ]);

            return FinancialTransaction::create([
                'tax_subject_id' => $subjectId,
                'account_id' => $account->id,
                'booked_at' => '2026-01-05',
                'amount_minor' => -1500,
                'currency_code' => 'RON',
                'description_ciphertext' => 'Cross-tenant test transaction',
                'counterparty_ciphertext' => 'Some Shop SRL',
                'source' => RecordSource::MANUAL,
            ]);
        },
        TransactionSplit::class => static function (string $subjectId): TransactionSplit {
            $account = FinancialAccount::create([
                'tax_subject_id' => $subjectId,
                'kind' => AccountKind::GENERAL,
                'display_name_ciphertext' => 'Split owner account',
                'currency_code' => 'RON',
                'source' => RecordSource::MANUAL,
            ]);
            $transaction = FinancialTransaction::create([
                'tax_subject_id' => $subjectId,
                'account_id' => $account->id,
                'booked_at' => '2026-01-05',
                'amount_minor' => -1500,
                'currency_code' => 'RON',
                'description_ciphertext' => 'Split owner transaction',
                'counterparty_ciphertext' => 'Some Shop SRL',
                'source' => RecordSource::MANUAL,
            ]);

            return TransactionSplit::create([
                'tax_subject_id' => $subjectId,
                'transaction_id' => $transaction->id,
                'amount_minor' => -1500,
                'category_code' => 'GROCERIES',
            ]);
        },
        DepositTerms::class => static function (string $subjectId): DepositTerms {
            $account = depositAccountFor($subjectId);

            // The terms row, not the account: the account has its own entry in this table above,
            // and this asserts the extension row is scoped independently rather than only reachable
            // through a scoped parent.
            return DepositTerms::query()->whereKey($account->id)->sole();
        },
        DepositRatePeriod::class => static function (string $subjectId): DepositRatePeriod {
            $account = depositAccountFor($subjectId);

            return DepositRatePeriod::create([
                'tax_subject_id' => $subjectId,
                'account_id' => $account->id,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'annual_rate_decimal' => '6.50',
            ]);
        },
        DepositInterestEvent::class => static function (string $subjectId): DepositInterestEvent {
            $account = depositAccountFor($subjectId);

            return DepositInterestEvent::create([
                'tax_subject_id' => $subjectId,
                'account_id' => $account->id,
                'accrual_from' => '2026-01-01',
                'accrual_to' => '2027-01-01',
                'currency_code' => 'RON',
                'gross_interest_minor' => 6500,
                'net_interest_minor' => 6500,
                'calculation_version' => 'test',
            ]);
        },
    ];
}

foreach (walletRowFactories() as $modelClass => $makeRow) {
    it("never lets one subject see another subject's {$modelClass} row", function () use ($modelClass, $makeRow): void {
        [$userA, $subjectA] = ownerAndSubject();
        [$userB, $subjectB] = ownerAndSubject();

        activateAs($userA, $subjectA);
        $rowA = $makeRow($subjectA->id);

        activateAs($userB, $subjectB);
        $makeRow($subjectB->id);

        /** @var array<int, Model> $visible */
        $visible = $modelClass::query()->get()->all();

        expect($visible)->toHaveCount(1);

        $visibleSubjectId = $visible[0]->getAttribute('tax_subject_id');
        assert(is_string($visibleSubjectId));
        expect($visibleSubjectId)->toBe($subjectB->id);

        /** @var Model|null $shouldBeInvisible */
        $shouldBeInvisible = $modelClass::find($rowA->getKey());
        expect($shouldBeInvisible)->toBeNull();
    });
}

/**
 * A TERM_DEPOSIT account with its terms — the pair that replaced the old single `deposits` row.
 *
 * Written out rather than through `OpenFinancialAccount` because this suite is about the tenant
 * scope on each model, so each fixture creates exactly the rows under test and nothing else.
 */
function depositAccountFor(string $subjectId): FinancialAccount
{
    $account = new FinancialAccount([
        'tax_subject_id' => $subjectId,
        'kind' => AccountKind::TERM_DEPOSIT,
        'display_name_ciphertext' => 'Depozit',
        'currency_code' => 'RON',
        'opened_at' => '2026-01-01',
        'source' => RecordSource::MANUAL,
    ]);
    $account->save();

    DepositTerms::create([
        'account_id' => $account->id,
        'tax_subject_id' => $subjectId,
        'principal_minor' => 100_000,
        'maturity_at' => '2027-01-01',
        'rate_type' => RateType::FIXED,
        'interest_method' => InterestMethod::SIMPLE,
        'payment_frequency' => PaymentFrequency::AT_MATURITY,
        'day_count_convention' => DayCountConvention::ACTUAL_365,
    ]);

    return $account;
}
