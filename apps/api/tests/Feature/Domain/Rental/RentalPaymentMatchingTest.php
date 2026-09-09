<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Rental\RentalPaymentMatchingTest;

use App\Domain\Identity\Models\User;
use App\Domain\Rental\Actions\ConfirmRentalPaymentTransactionMatch;
use App\Domain\Rental\Actions\CreateRentalContract;
use App\Domain\Rental\Actions\ProposeRentalPaymentTransactionMatch;
use App\Domain\Rental\Actions\RecordRentalPayment;
use App\Domain\Rental\Actions\RegisterProperty;
use App\Domain\Rental\Actions\RejectRentalPaymentTransactionMatch;
use App\Domain\Rental\DataTransferObjects\CreateRentalContractInput;
use App\Domain\Rental\DataTransferObjects\RecordRentalPaymentInput;
use App\Domain\Rental\DataTransferObjects\RegisterPropertyInput;
use App\Domain\Rental\Enums\PropertyType;
use App\Domain\Rental\Enums\RentalPaymentFrequency;
use App\Domain\Rental\Enums\TenantType;
use App\Domain\Rental\Enums\TransactionMatchStatus;
use App\Domain\Rental\Exceptions\InvalidTransactionMatchTransitionException;
use App\Domain\Rental\Models\RentalPayment;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Subjects\Tenancy\TenantContext;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\FinancialTransaction;
use Illuminate\Support\Facades\Artisan;

/**
 * Matching proposes links between a `rental_payments` row and a `Domain/Wallet`
 * `financial_transactions` row; it never confirms one automatically (`BLUEPRINT.md` §14.6's
 * "never auto-confirm" principle, applied to a financial link exactly as it applies to a document
 * extraction). Wallet's Eloquent models are used freely here (in tests only — `deptrac.php`
 * excludes `tests/`) to set up a realistic fixture; production code under `app/Domain/Rental`
 * never imports them (see `App\Domain\Rental\Services\Matching\TransactionMatchCandidateFinder`).
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    bootstrapIdentityCryptoForTests();
});

function activatedMatchingSubject(): TaxSubject
{
    $subject = TaxSubject::factory()->create();
    $user = User::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    $context = new TenantContext();
    $context->authorizeAndActivate($user->id, $subject->id);
    app()->instance(TenantContext::class, $context);

    return $subject;
}

function makeMatchingWalletTransaction(string $subjectId, int $amountMinor, string $bookedAt): FinancialTransaction
{
    $account = FinancialAccount::create([
        'tax_subject_id' => $subjectId,
        'kind' => AccountKind::BANK_CURRENT,
        'display_name_ciphertext' => 'Landlord current account',
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    return FinancialTransaction::create([
        'tax_subject_id' => $subjectId,
        'account_id' => $account->id,
        'booked_at' => $bookedAt,
        'amount_minor' => $amountMinor,
        'currency_code' => 'RON',
        'description_ciphertext' => 'Incoming rent transfer',
        'counterparty_ciphertext' => 'Tenant transfer',
        'source' => RecordSource::MANUAL,
    ]);
}

function makeMatchingContract(string $subjectId): string
{
    $property = app(RegisterProperty::class)(new RegisterPropertyInput(
        taxSubjectId: $subjectId,
        type: PropertyType::APARTMENT,
        address: 'Matching test property',
        ownershipPercentDecimal: '100.00',
    ));

    return app(CreateRentalContract::class)(new CreateRentalContractInput(
        taxSubjectId: $subjectId,
        propertyId: $property->id,
        tenantType: TenantType::PF,
        tenantIdentity: 'Matching tenant',
        startDate: '2026-01-01',
        currencyCode: 'RON',
        rentMinor: 100_000,
        paymentFrequency: RentalPaymentFrequency::MONTHLY,
    ))->id;
}

it('proposes a match for a transaction with the same amount, currency, and a nearby date, but never auto-confirms it', function (): void {
    $subject = activatedMatchingSubject();
    $contractId = makeMatchingContract($subject->id);

    $transaction = makeMatchingWalletTransaction($subject->id, 100_000, '2026-01-06');

    $payment = app(RecordRentalPayment::class)(new RecordRentalPaymentInput(
        taxSubjectId: $subject->id,
        rentalContractId: $contractId,
        amountMinor: 100_000,
        currencyCode: 'RON',
        paidAt: '2026-01-05',
    ));

    expect($payment->transaction_match_status)->toBe(TransactionMatchStatus::UNMATCHED)
        ->and($payment->transaction_id)->toBeNull();

    $proposed = app(ProposeRentalPaymentTransactionMatch::class)($payment);

    expect($proposed->transaction_match_status)->toBe(TransactionMatchStatus::PROPOSED)
        ->and($proposed->transaction_id)->toBe($transaction->id);
});

it('confirms a proposed match only via an explicit human decision', function (): void {
    $subject = activatedMatchingSubject();
    $contractId = makeMatchingContract($subject->id);

    makeMatchingWalletTransaction($subject->id, 100_000, '2026-01-05');

    $payment = app(RecordRentalPayment::class)(new RecordRentalPaymentInput(
        taxSubjectId: $subject->id,
        rentalContractId: $contractId,
        amountMinor: 100_000,
        currencyCode: 'RON',
        paidAt: '2026-01-05',
    ));

    $proposed = app(ProposeRentalPaymentTransactionMatch::class)($payment);
    expect($proposed->transaction_match_status)->toBe(TransactionMatchStatus::PROPOSED);

    $confirmed = app(ConfirmRentalPaymentTransactionMatch::class)($proposed);
    expect($confirmed->transaction_match_status)->toBe(TransactionMatchStatus::CONFIRMED);
});

it('rejects a proposed match while keeping the candidate visible for audit', function (): void {
    $subject = activatedMatchingSubject();
    $contractId = makeMatchingContract($subject->id);

    $transaction = makeMatchingWalletTransaction($subject->id, 100_000, '2026-01-05');

    $payment = app(RecordRentalPayment::class)(new RecordRentalPaymentInput(
        taxSubjectId: $subject->id,
        rentalContractId: $contractId,
        amountMinor: 100_000,
        currencyCode: 'RON',
        paidAt: '2026-01-05',
    ));

    $proposed = app(ProposeRentalPaymentTransactionMatch::class)($payment);
    $rejected = app(RejectRentalPaymentTransactionMatch::class)($proposed);

    expect($rejected->transaction_match_status)->toBe(TransactionMatchStatus::REJECTED)
        ->and($rejected->transaction_id)->toBe($transaction->id);
});

it('refuses to confirm or reject a match that was never proposed', function (): void {
    $subject = activatedMatchingSubject();
    $contractId = makeMatchingContract($subject->id);

    $payment = app(RecordRentalPayment::class)(new RecordRentalPaymentInput(
        taxSubjectId: $subject->id,
        rentalContractId: $contractId,
        amountMinor: 100_000,
        currencyCode: 'RON',
        paidAt: '2026-01-05',
    ));

    expect(fn () => app(ConfirmRentalPaymentTransactionMatch::class)($payment))
        ->toThrow(InvalidTransactionMatchTransitionException::class);
    expect(fn () => app(RejectRentalPaymentTransactionMatch::class)($payment))
        ->toThrow(InvalidTransactionMatchTransitionException::class);
});

it('does not propose a candidate outside the matching date window', function (): void {
    $subject = activatedMatchingSubject();
    $contractId = makeMatchingContract($subject->id);

    // Booked ten days away from the payment's own paid_at — outside the matching window.
    makeMatchingWalletTransaction($subject->id, 100_000, '2026-01-15');

    $payment = app(RecordRentalPayment::class)(new RecordRentalPaymentInput(
        taxSubjectId: $subject->id,
        rentalContractId: $contractId,
        amountMinor: 100_000,
        currencyCode: 'RON',
        paidAt: '2026-01-05',
    ));

    $result = app(ProposeRentalPaymentTransactionMatch::class)($payment);

    expect($result->transaction_match_status)->toBe(TransactionMatchStatus::UNMATCHED)
        ->and($result->transaction_id)->toBeNull();
});

it('never proposes a transaction that is already CONFIRMED against a different rental payment', function (): void {
    $subject = activatedMatchingSubject();
    $contractId = makeMatchingContract($subject->id);

    makeMatchingWalletTransaction($subject->id, 100_000, '2026-01-05');

    $firstPayment = app(RecordRentalPayment::class)(new RecordRentalPaymentInput(
        taxSubjectId: $subject->id,
        rentalContractId: $contractId,
        amountMinor: 100_000,
        currencyCode: 'RON',
        paidAt: '2026-01-05',
    ));
    $firstProposed = app(ProposeRentalPaymentTransactionMatch::class)($firstPayment);
    app(ConfirmRentalPaymentTransactionMatch::class)($firstProposed);

    /** @var RentalPayment $secondPayment */
    $secondPayment = app(RecordRentalPayment::class)(new RecordRentalPaymentInput(
        taxSubjectId: $subject->id,
        rentalContractId: $contractId,
        amountMinor: 100_000,
        currencyCode: 'RON',
        paidAt: '2026-01-06',
    ));
    $secondProposed = app(ProposeRentalPaymentTransactionMatch::class)($secondPayment);

    expect($secondProposed->transaction_match_status)->toBe(TransactionMatchStatus::UNMATCHED)
        ->and($secondProposed->transaction_id)->toBeNull();
});
