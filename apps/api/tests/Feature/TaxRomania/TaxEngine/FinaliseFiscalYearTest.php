<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kern\TaxRomania\TaxEngine\Actions\AssessFiscalYear;
use Kern\TaxRomania\TaxEngine\Actions\FinaliseFiscalYear;
use Kern\TaxRomania\TaxEngine\Actions\UnlockFiscalYear;
use Kern\TaxRomania\TaxEngine\Enums\AssessmentStatus;
use Kern\TaxRomania\TaxEngine\Exceptions\CandidateNotAllowedInFinalAssessmentException;
use Kern\TaxRomania\TaxEngine\Exceptions\FiscalYearIsFinalException;
use Kern\TaxRomania\TaxEngine\Exceptions\FiscalYearNotClosedException;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;

/**
 * Marking a year final, and taking it back.
 */
beforeEach(function (): void {
    seedRuleParameters();
    Artisan::call('kern:tax:register-rules');
    fakeLedger([]);
});

it('refuses a year that has not ended', function (): void {
    /*
     * Not an arithmetic rule — the engine would compute it happily. Income can still arrive, so a figure
     * called final in October would be wrong in November, and the lock it places would stop anybody
     * recording the income that proves it wrong.
     */
    $subject = assessableSubject();
    approveRegisteredRules();

    expect(fn () => app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2026-10-09')))
        ->toThrow(FiscalYearNotClosedException::class);
});

it('allows the year before, from its first day', function (): void {
    /*
     * 2026 with the clock in 2027, not 2025 with the clock in 2026: this fixture's parameters are valid from
     * 2026-01-01, so no rule is in force on 2025-12-31 and the test would fail for a reason that has nothing
     * to do with what it is asserting.
     */
    $subject = assessableSubject();
    approveRegisteredRules();

    $final = app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    expect($final->status)->toBe(AssessmentStatus::FINAL);
});

it('refuses to finalise on a rule nobody has approved', function (): void {
    // The whole reason finalising recomputes rather than relabelling: the strict mode is what makes the
    // status true, and a draft was computed in a mode that permits exactly this.
    $subject = assessableSubject();

    expect(fn () => app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01')))
        ->toThrow(CandidateNotAllowedInFinalAssessmentException::class);
});

it('replaces the draft rather than sitting beside it', function (): void {
    $subject = assessableSubject();
    approveRegisteredRules();
    app(AssessFiscalYear::class)($subject->id, 2026);

    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    expect(TaxAssessment::query()->where('fiscal_year', 2026)->count())->toBe(1);
});

it('unlocks back to a draft, keeping the figures and the row', function (): void {
    /*
     * Unlocking does not recompute: nothing has changed yet, and it is the step before changing something.
     * Calling a final figure a draft only ever weakens the claim, which is safe.
     */
    $subject = assessableSubject();
    approveRegisteredRules();
    $final = app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));
    $totals = $final->totals_json;

    $unlocked = app(UnlockFiscalYear::class)($final);

    expect($unlocked->id)->toBe($final->id)
        ->and($unlocked->status)->toBe(AssessmentStatus::DRAFT)
        ->and($unlocked->totals_json)->toBe($totals)
        ->and(TaxAssessment::query()->where('fiscal_year', 2026)->count())->toBe(1);
});

it('refuses to recompute a final year', function (): void {
    /*
     * The screen hides the button, but a screen is not a rule. Recalculating silently wrote a draft over the
     * final one — which unlocked a year's transactions without anybody deciding to, since the lock is derived
     * from the status.
     */
    $subject = assessableSubject();
    approveRegisteredRules();
    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    expect(fn () => app(AssessFiscalYear::class)($subject->id, 2026))
        ->toThrow(FiscalYearIsFinalException::class);
});

it('refuses to finalise a year that is already final', function (): void {
    $subject = assessableSubject();
    approveRegisteredRules();
    app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01'));

    expect(fn () => app(FinaliseFiscalYear::class)($subject->id, 2026, new DateTimeImmutable('2027-01-01')))
        ->toThrow(FiscalYearIsFinalException::class);
});
