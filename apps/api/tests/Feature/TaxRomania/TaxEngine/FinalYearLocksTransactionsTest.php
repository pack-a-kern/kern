<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Kern\TaxRomania\Database\Factories\FinancialAccountFactory;
use Kern\TaxRomania\TaxEngine\Actions\FinaliseFiscalYear;
use Kern\TaxRomania\TaxEngine\Actions\UnlockFiscalYear;
use Kern\TaxRomania\TaxEngine\Exceptions\FiscalYearIsFinalException;
use Kern\TaxRomania\TaxEngine\Services\FinalYearGuard;
use Kern\TaxRomania\Wallet\Actions\ClassifyTransaction;
use Kern\TaxRomania\Wallet\Actions\DeleteFinancialTransaction;
use Kern\TaxRomania\Wallet\DataTransferObjects\TransactionSplitInput;
use Kern\TaxRomania\Wallet\Models\FinancialAccount;
use Kern\TaxRomania\Wallet\Models\FinancialTransaction;

/**
 * A year marked final locks the transactions its figures were computed from.
 *
 * Five paths can break a final assessment, and the obvious one is the least likely: editing, recategorising
 * and merging duplicates change what the figures rest on just as thoroughly as deleting, and **adding** a
 * transaction to a finalised year does too — the one people forget, because nothing is being changed, only
 * introduced.
 *
 * The guard lives in the actions rather than the controllers, because importers and document parsers reach
 * the actions without passing through a request.
 */
beforeEach(function (): void {
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');
    fakeLedger([]);
});

function transactionIn(string $subjectId, string $bookedAt): FinancialTransaction
{
    /** @var FinancialAccount $account */
    $account = FinancialAccountFactory::new()->createOne(['tax_subject_id' => $subjectId]);

    $transaction = new FinancialTransaction();
    $transaction->tax_subject_id = $subjectId;
    $transaction->account_id = $account->id;
    $transaction->booked_at = Carbon::parse($bookedAt);
    $transaction->amount_minor = 150000;
    $transaction->currency_code = 'RON';
    $transaction->description_ciphertext = 'A payment';
    $transaction->counterparty_ciphertext = 'A counterparty';
    $transaction->save();

    return $transaction;
}

it('refuses to delete a transaction in a final year', function (): void {
    $subject = assessableSubject();
    approveRegisteredRules();
    $transaction = transactionIn($subject->id, '2026-04-02 09:00:00');
    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    expect(fn () => app(DeleteFinancialTransaction::class)($transaction))
        ->toThrow(FiscalYearIsFinalException::class);
});

it('refuses to recategorise one, which is no smaller a change', function (): void {
    $subject = assessableSubject();
    approveRegisteredRules();
    $transaction = transactionIn($subject->id, '2026-04-02 09:00:00');
    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    expect(fn () => app(ClassifyTransaction::class)($transaction, [
        new TransactionSplitInput(amountMinor: 150000, categoryCode: 'FREELANCE'),
    ]))->toThrow(FiscalYearIsFinalException::class);
});

it('refuses to add a transaction to a final year', function (): void {
    // Nothing is being changed, only introduced — and income arriving after the figure was called final
    // makes it wrong in exactly the way deleting one does.
    $subject = assessableSubject();
    approveRegisteredRules();
    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    expect(fn () => app(FinalYearGuard::class)->assertYearIsOpen($subject->id, new DateTimeImmutable('2026-06-01')))
        ->toThrow(FiscalYearIsFinalException::class);
});

it('leaves other years alone', function (): void {
    $subject = assessableSubject();
    approveRegisteredRules();
    $other = transactionIn($subject->id, '2024-04-02 09:00:00');
    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    app(DeleteFinancialTransaction::class)($other);

    expect(FinancialTransaction::query()->withoutGlobalScopes()->whereKey($other->id)->exists())->toBeFalse();
});

it('lets the transaction go once the year is unlocked', function (): void {
    /*
     * The four presses: unlock, change, recalculate, finalise. This is the first two — and the reason a
     * final assessment can never point at a deleted row, because the moment one can be deleted the figures
     * are a draft again.
     */
    $subject = assessableSubject();
    approveRegisteredRules();
    $transaction = transactionIn($subject->id, '2026-04-02 09:00:00');
    $final = app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    app(UnlockFiscalYear::class)($final);
    app(DeleteFinancialTransaction::class)($transaction);

    expect(FinancialTransaction::query()->withoutGlobalScopes()->whereKey($transaction->id)->exists())->toBeFalse();
});

it('names the year it refused, so a screen need not parse the sentence', function (): void {
    $subject = assessableSubject();
    approveRegisteredRules();
    $transaction = transactionIn($subject->id, '2026-04-02 09:00:00');
    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    try {
        app(DeleteFinancialTransaction::class)($transaction);
        $this->fail('Expected the year to be locked.');
    } catch (FiscalYearIsFinalException $exception) {
        expect($exception->fiscalYear)->toBe(2026)->and($exception->getMessage())->toContain('2026');
    }
});
