<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\DataTransferObjects\DepositRatePeriodInput;
use App\Domain\Wallet\Events\DepositInterestEventRecorded;
use App\Domain\Wallet\Models\DepositInterestEvent;
use App\Domain\Wallet\Models\DepositRatePeriod;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Services\Fx\DepositInterestFxAnnotator;
use App\Domain\Wallet\Services\Interest\DepositInterestCalculator;
use Illuminate\Support\Facades\DB;

/**
 * (Re)computes a deposit's full `deposit_interest_events` schedule and persists it — the entry
 * point that turns `App\Domain\Wallet\Services\Interest\DepositInterestCalculator`'s pure output
 * into stored rows, annotated with FX provenance for a non-RON deposit
 * (`App\Domain\Wallet\Services\Fx\DepositInterestFxAnnotator`), and dispatches
 * `App\Domain\Wallet\Events\DepositInterestEventRecorded` for each row after the transaction
 * commits.
 *
 * **Idempotent and safe to re-run** (e.g. after adding a new rate period to a `VARIABLE` deposit):
 * matched by the unique `(account_id, accrual_from, accrual_to)` key,
 * - a period whose row does not exist yet is inserted;
 * - a period whose row exists and has **not** been marked `paid_at` is recalculated and updated in
 *   place (an existing `withholding_minor`, if one was ever recorded via
 *   `App\Domain\Wallet\Actions\RecordDepositInterestWithholding`, is preserved and `net_interest_
 *   minor` is recomputed from it and the new gross figure);
 * - a period whose row exists **and has** `paid_at` set is left completely untouched — a realized,
 *   already-paid interest event is immutable, never silently recalculated
 *   (`docs/AGENTS.md` §1 rule 17's spirit: never overwrite a settled record).
 *
 * Requires an active `App\Domain\Subjects\Tenancy\TenantContext` authorized for the deposit's own
 * `tax_subject_id` — every model this action reads/writes is tenant-scoped
 * (`App\Domain\Subjects\Tenancy\ScopedToTaxSubject`).
 */
final readonly class GenerateDepositInterestSchedule
{
    public const string CALCULATION_VERSION = 'wallet.deposit-interest.v1';

    private const string RON = 'RON';

    public function __construct(
        private DepositInterestCalculator $calculator,
        private DepositInterestFxAnnotator $fxAnnotator,
    ) {
    }

    /**
     * @return list<DepositInterestEvent>
     */
    public function __invoke(FinancialAccount $account): array
    {
        $terms = $account->depositTerms;

        if ($terms === null) {
            // Not a term deposit. Returning nothing beats throwing: callers regenerate schedules
            // over a set of accounts, and an ordinary current account in that set is not an error.
            return [];
        }

        $ratePeriodInputs = array_values($account->depositRatePeriods
            ->map(static fn (DepositRatePeriod $period): DepositRatePeriodInput => new DepositRatePeriodInput(
                validFrom: $period->valid_from->format('Y-m-d'),
                validTo: $period->valid_to?->format('Y-m-d'),
                annualRateDecimal: $period->annual_rate_decimal,
            ))
            ->all());

        $computedPeriods = $this->calculator->calculate(
            principalMinor: $terms->principal_minor,
            ratePeriods: $ratePeriodInputs,
            method: $terms->interest_method,
            frequency: $terms->payment_frequency,
            dayCountConvention: $terms->day_count_convention,
            // Both dates come from the terms now: a source is a folder that may hold several
            // products, so it cannot carry one start date for all of them.
            openedAt: $terms->starts_at->toImmutable(),
            maturityAt: $terms->maturity_at->toImmutable(),
        );

        $events = DB::transaction(function () use ($account, $computedPeriods): array {
            $recorded = [];

            foreach ($computedPeriods as $period) {
                /** @var DepositInterestEvent|null $existing */
                $existing = DepositInterestEvent::query()
                    ->where('account_id', $account->id)
                    ->whereDate('accrual_from', $period->accrualFrom->format('Y-m-d'))
                    ->whereDate('accrual_to', $period->accrualTo->format('Y-m-d'))
                    ->first();

                if ($existing !== null && $existing->paid_at !== null) {
                    $recorded[] = $existing;

                    continue;
                }

                $fxAnnotation = $account->currency_code === self::RON
                    ? null
                    // `tryAnnotate`, not `annotate`: the schedule spans the deposit's whole life,
                    // so most periods end on a date the BNR has not published a rate for yet. Those
                    // rows store the gross interest with no FX chain and are filled in on a later
                    // regeneration; failing the whole schedule instead would leave a foreign-
                    // currency deposit with no interest figure at all until its maturity.
                    : $this->fxAnnotator->tryAnnotate($account->currency_code, $period->accrualTo, $period->grossInterestMinor);

                $withholdingMinor = $existing?->withholding_minor;
                $netInterestMinor = $period->grossInterestMinor - ($withholdingMinor ?? 0);

                $attributes = [
                    'tax_subject_id' => $account->tax_subject_id,
                    'account_id' => $account->id,
                    'accrual_from' => $period->accrualFrom->format('Y-m-d'),
                    'accrual_to' => $period->accrualTo->format('Y-m-d'),
                    'currency_code' => $account->currency_code,
                    'gross_interest_minor' => $period->grossInterestMinor,
                    'withholding_minor' => $withholdingMinor,
                    'net_interest_minor' => $netInterestMinor,
                    'calculation_version' => self::CALCULATION_VERSION,
                    'fx_rate_decimal' => $fxAnnotation?->perUnitRateDecimal,
                    'fx_rate_date' => $fxAnnotation?->rateDate->format('Y-m-d'),
                    'fx_source_code' => $fxAnnotation?->sourceCode,
                    'fx_method' => $fxAnnotation?->method,
                    'fx_content_sha256' => $fxAnnotation?->contentSha256,
                    'fx_source_document_version_id' => $fxAnnotation?->sourceDocumentVersionId,
                    'ron_equivalent_minor' => $fxAnnotation?->ronEquivalentMinor,
                ];

                if ($existing !== null) {
                    $existing->fill($attributes);
                    $existing->save();
                    $recorded[] = $existing;
                } else {
                    $recorded[] = DepositInterestEvent::create($attributes);
                }
            }

            return $recorded;
        });

        foreach ($events as $event) {
            event(new DepositInterestEventRecorded(
                depositInterestEventId: $event->id,
                taxSubjectId: $event->tax_subject_id,
                depositId: $event->account_id,
                accrualFrom: $event->accrual_from->toImmutable(),
                accrualTo: $event->accrual_to->toImmutable(),
                currencyCode: $event->currency_code,
                grossInterestMinor: $event->gross_interest_minor,
                withholdingMinor: $event->withholding_minor,
                netInterestMinor: $event->net_interest_minor,
                ronEquivalentMinor: $event->ron_equivalent_minor,
                fxRateDecimal: $event->fx_rate_decimal,
                fxSourceCode: $event->fx_source_code,
            ));
        }

        return $events;
    }
}
