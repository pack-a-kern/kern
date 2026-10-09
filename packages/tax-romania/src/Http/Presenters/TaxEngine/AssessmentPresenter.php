<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Presenters\TaxEngine;

use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine;
use Kern\TaxRomania\TaxEngine\Models\TaxFact;

/**
 * One assessment, as a screen reads it.
 *
 * Shared by the endpoint that computes an assessment and the one that reads a saved one back, because a draft
 * somebody returns to tomorrow has to arrive in the same shape as the one they watched appear. Two builders of
 * one payload drift, and the drift shows as a figure that renders on one path and a dash on the other.
 */
final class AssessmentPresenter
{
    /** @return array<string, mixed> */
    public function present(TaxAssessment $assessment): array
    {
        $lines = TaxAssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->orderBy('tax_code')
            ->orderBy('category_code')
            ->get()
            ->map(fn (TaxAssessmentLine $line): array => [
                'tax_code' => $line->tax_code,
                'category_code' => $line->category_code,
                'taxable_base_minor' => $line->taxable_base_minor,
                'rate_decimal' => $line->rate_decimal,
                'tax_minor' => $line->tax_minor,
                // The key a screen renders, and the trace behind it. Both travel together because a figure
                // without its reasoning is the thing this whole engine exists not to produce.
                'explanation_key' => $line->explanation_key,
                /*
                 * The income the rule actually summed, and how it got from there to the base.
                 *
                 * Pulled out of the trace rather than left in it, because the screen showed a base of 103,800
                 * with nothing about the 475,690 behind it — so a contribution the rule describes as "a
                 * percentage of the threshold, not of what you earned" was displayed without what was earned
                 * anywhere on it. The figure the whole rule is about was the one figure missing.
                 */
                'income_minor' => $this->summedIncomeMinor($line),
                'base_reached' => $this->baseStep($line),
                'trace' => $line->calculation_trace_json,
            ])
            ->all();

        return [
            'id' => $assessment->id,
            'fiscal_year' => $assessment->fiscal_year,
            'status' => $assessment->status->value,
            /* When it was computed, because a draft read back a week later is only trustworthy with its date. */
            'computed_at' => $assessment->created_at?->toIso8601String(),
            'lines' => $lines,
            /*
             * The engine's own totals, not a sum the screen performs.
             *
             * A screen that adds money is a second place money is decided, and the two drift. These come from
             * `tax_assessments.totals_json`, which the recorder wrote from the lines it had just recorded.
             */
            'totals' => $this->totalsByTax($assessment),
            /*
             * The year's income, read from the facts rather than added up from the lines.
             *
             * The same freelance income is read by three rules, so three lines carry it and their sum is three
             * times what was earned. The facts are the one place each income exists exactly once.
             */
            'income_assessed_minor' => (int) TaxFact::query()
                ->where('tax_subject_id', $assessment->tax_subject_id)
                ->where('fiscal_year', $assessment->fiscal_year)
                ->sum('ron_amount_minor'),
        ];
    }

    /**
     * What each tax comes to, as the assessment recorded it.
     *
     * Read from the row rather than summed from the lines: a controller that adds money is a second place money
     * is decided, and the two drift. `totals_json` is what the recorder wrote from the lines it had just
     * recorded, so the screen and the assessment cannot disagree.
     *
     * @return array<string, array{minor_units: int, currency: string}>
     */
    private function totalsByTax(TaxAssessment $assessment): array
    {
        $out = [];

        foreach ($assessment->totals_json as $taxCode => $total) {
            if (! is_array($total)) {
                continue;
            }

            $minor = $total['minor_units'] ?? null;
            $currency = $total['currency'] ?? null;

            if (is_int($minor) && is_string($currency)) {
                $out[$taxCode] = ['minor_units' => $minor, 'currency' => $currency];
            }
        }

        return $out;
    }

    /**
     * What the rule summed before it did anything, as a string.
     *
     * The first step, by convention every rule in `TaxEngine/Rules` follows: it sums the facts of its own
     * categories and records that as step one — `sum_qualifying_income`, `sum_gross_rent`, `sum_gains`. Reading
     * position rather than operation name keeps this from having to know which rules exist, and a rule that
     * broke the convention would show no income rather than a wrong one.
     */
    private function summedIncomeMinor(TaxAssessmentLine $line): ?int
    {
        $steps = $line->calculation_trace_json['steps'] ?? null;
        $first = is_array($steps) ? ($steps[0] ?? null) : null;
        $result = is_array($first) ? ($first['result'] ?? null) : null;

        return is_string($result) ? $this->minorUnitsOf($result) : null;
    }

    /**
     * A `Money` as it appears in a trace, back to minor units.
     *
     * `Money::toString()` writes a fixed machine form — sign, digits, a dot, two places, the currency — which is
     * right for a trace that has to read the same in five years, and wrong on a screen: it put `2177598.46 RON`
     * beside `2.177.598,46 RON` in the same row, the second being the reader's own locale.
     *
     * So the figure travels as an integer and the screen formats it, which is how every other amount on that page
     * already works. Parsing a formatted string is usually a mistake; here the format is fixed by the class two
     * files away and asserted by a test, and the alternative — a second representation in every rule's trace —
     * changes six rules to fix a display bug.
     */
    private function minorUnitsOf(string $money): ?int
    {
        if (preg_match('/^(-?)(\d+)\.(\d{2}) [A-Z]{3}$/', $money, $matches) !== 1) {
            return null;
        }

        return (int) ($matches[1] . $matches[2] . $matches[3]);
    }

    /**
     * How the rule got from that income to the base it taxed, as the step's own detail.
     *
     * For the contribution it is which threshold was reached; for rent, the expense quota deducted. Returned as
     * the step recorded it rather than reworded here, because the rule is what knows why.
     *
     * @return array<string, string>|null
     */
    private function baseStep(TaxAssessmentLine $line): ?array
    {
        $steps = $line->calculation_trace_json['steps'] ?? null;
        $second = is_array($steps) ? ($steps[1] ?? null) : null;

        if (! is_array($second)) {
            return null;
        }

        $detail = $second['detail'] ?? null;
        $operation = $second['operation'] ?? null;
        $out = ['operation' => is_string($operation) ? $operation : ''];

        foreach (is_array($detail) ? $detail : [] as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            /*
             * An amount in the detail is sent as minor units under `<key>_minor`, for the same reason as the
             * income above: the trace's own form is not the reader's. The string stays too, because a detail
             * this screen has not been taught about must still be printable.
             */
            $minor = $this->minorUnitsOf($value);

            if ($minor !== null) {
                $out[$key . '_minor'] = (string) $minor;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
