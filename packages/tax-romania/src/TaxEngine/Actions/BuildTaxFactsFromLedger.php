<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Actions;

use DateTimeImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Kern\TaxRomania\TaxEngine\Contracts\LedgerIncomeProvider;
use Kern\TaxRomania\TaxEngine\Enums\FxConversionMethod;
use Kern\TaxRomania\TaxEngine\Enums\RoundingMode;
use Kern\TaxRomania\TaxEngine\Enums\TaxFactStatus;
use Kern\TaxRomania\TaxEngine\Models\TaxFact;
use Kern\TaxRomania\TaxEngine\Services\FxConversionPolicy;
use Kern\TaxRomania\TaxEngine\ValueObjects\Money;

/**
 * Turns a year's categorised ledger into the facts the engine computes from.
 *
 * ## Why facts exist at all, rather than rules reading transactions
 *
 * A transaction is what a bank did; a fact is what the law counts, and the two differ often enough that
 * collapsing them loses what no rule could recover. A transfer between a person's own accounts is a
 * transaction and not income. Interest earned in December and credited in January belongs to one year and
 * appears in another. The gross a return declares is not always the net that arrived.
 *
 * So this is the one place that reads the ledger and decides. A rule may query nothing — which is what makes
 * a figure reproducible — so it receives facts and nothing else.
 *
 * ## One fact per split, and the rules total them
 *
 * `source_type`/`source_id` point at the row a fact came from, so a fact that aggregated twenty splits could
 * not name its own evidence. Every rule already sums the facts it is given, and `CassOtherIncomeRule`'s
 * thresholds apply to that sum, so aggregating here would buy nothing and cost the provenance.
 *
 * ## It does not read the ledger itself
 *
 * `LedgerIncomeProvider` does, and `Wallet` implements it. The first version of this read `TransactionSplit`
 * and `FinancialTransaction` straight from here and deptrac refused it — rightly, and after a comment had
 * already been written acknowledging the very boundary it crossed. The dependency points inward now, the way
 * ADR 0010 requires and the two parameter ports beside it already do.
 *
 * ## Which categories count
 *
 * The `income` group of `config/reference/tax_categories.php`, which that file is explicit about being "a
 * label the screen uses — this is income versus this is my own money moving — not a rule that decides what
 * is owed". Reading it to decide *whether money is income at all* is exactly that label; what is owed stays
 * with the rules.
 *
 * `SALARY_PENSION` is left out although it is income: an employer withholds and declares it, and counting it
 * toward another regime's thresholds would be wrong rather than merely incomplete.
 *
 * ## Confirmed, because the categorisation was the confirmation
 *
 * These were written `PROPOSED` first, on the reasoning that a fact resting on a judgement should be reviewed.
 * But the judgement had already been made and by a person: `transaction_splits.category_code` is what somebody
 * chose on the transactions screen, and a fact built from it adds no claim of its own — it converts a currency
 * and totals nothing.
 *
 * `TaxFactStatus`'s own words are about a different source: "a fact **an AI extraction pipeline proposes** is
 * always `PROPOSED`". That is §14.6's subject — a figure read out of a document by a model, which a person must
 * see before it reaches a return. Applying it here asked somebody to confirm their own categorisation twice and
 * blocked every `FINAL` assessment for no information gained.
 *
 * So `PROPOSED` stays for the document-extraction path, which nothing builds yet, and a fact derived from a
 * person's own categorisation is `CONFIRMED`.
 *
 * ## Replaced, not accumulated
 *
 * A second run for the same year deletes what an earlier one produced before writing. A fact is a reading of
 * the ledger as it stands, so a corrected ledger should yield a corrected fact rather than a second one
 * beside it. Only facts this action produced are removed — `source_type` is what tells them apart from a
 * document extraction's or a rental accrual's.
 */
final readonly class BuildTaxFactsFromLedger
{
    /** Income the payer withholds and declares, so it is not a fact for these rules. */
    private const array WITHHELD_BY_PAYER = ['SALARY_PENSION'];

    /** Marks the facts this action owns, so a rerun replaces its own and nothing else's. */
    public const string SOURCE_TYPE = 'transaction_splits';

    public function __construct(
        private ConfigRepository $config,
        private FxConversionPolicy $fx,
        private LedgerIncomeProvider $ledger,
    ) {
    }

    /** @return list<TaxFact> the facts now recorded for that year */
    public function __invoke(string $taxSubjectId, int $fiscalYear): array
    {
        TaxFact::query()
            ->where('tax_subject_id', $taxSubjectId)
            ->where('fiscal_year', $fiscalYear)
            ->where('source_type', self::SOURCE_TYPE)
            ->delete();

        $facts = [];

        $income = $this->ledger->incomeIn($taxSubjectId, $fiscalYear, $this->incomeCategories());

        foreach ($income as $entry) {
            $original = Money::ofMinorUnits($entry->amountMinor, $entry->currencyCode);
            $bookedAt = $entry->bookedAt;

            /*
             * Converted at the booking date, not once at a year-end rate: money received in March was worth
             * March's rate, and one rate for a whole year is a figure no source supports.
             * `PREVIOUS_BANKING_DAY` because BNR publishes one rate per working day, so a Saturday has none
             * of its own — and the provenance of which day was used is recorded beside the amount.
             */
            $conversion = $original->currencyCode === 'RON'
                ? null
                : $this->fx->convertToRon(
                    amount: $original,
                    method: FxConversionMethod::PREVIOUS_BANKING_DAY,
                    asOf: $bookedAt,
                    roundingMode: RoundingMode::HALF_UP,
                );

            $ron = $conversion === null ? $original : $conversion->convertedAmount;

            /** @var TaxFact $fact */
            $fact = TaxFact::query()->create([
                'tax_subject_id' => $taxSubjectId,
                'fiscal_year' => $fiscalYear,
                'category_code' => $entry->categoryCode,
                // The booking date is the recognition date here, and only here. A rule that needs a different
                // one — rent accrued rather than received — gets it from a producer that knows the contract,
                // not from a bank line.
                'recognition_date' => $bookedAt->format('Y-m-d'),
                'cash_date' => $bookedAt->format('Y-m-d'),
                'transaction_date' => $bookedAt->format('Y-m-d'),
                'gross_amount_minor' => $original->minorUnits,
                'currency_code' => $original->currencyCode,
                'ron_amount_minor' => $ron->minorUnits,
                'fx_conversion_json' => $conversion === null ? null : [
                    'method' => $conversion->method->value,
                    'rate' => $conversion->rateApplied->toString(),
                    'resolved_for' => $conversion->resolvedFor->format('Y-m-d'),
                    'source' => $conversion->sourceCode,
                ],
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $entry->sourceId,
                'status' => TaxFactStatus::CONFIRMED->value,
            ]);

            $facts[] = $fact;
        }

        return $facts;
    }

    /** @return list<string> */
    private function incomeCategories(): array
    {
        $income = $this->config->get('reference.tax_categories.income');
        $codes = [];

        foreach (is_array($income) ? array_keys($income) : [] as $code) {
            if (is_string($code) && ! in_array($code, self::WITHHELD_BY_PAYER, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
