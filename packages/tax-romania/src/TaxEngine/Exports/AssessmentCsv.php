<?php

declare(strict_types=1);

namespace Kern\TaxRomania\TaxEngine\Exports;

use Kern\TaxRomania\TaxEngine\Models\TaxAssessment;
use Kern\TaxRomania\TaxEngine\Models\TaxAssessmentLine;
use Kern\TaxRomania\TaxEngine\Models\TaxFact;

/**
 * An assessment as two CSVs: what was computed, and what it was computed from.
 *
 * ## Why two
 *
 * The figures alone are an assertion. An accountant reading "CASS 41.520,00" wants to see the income behind
 * it, and a year later so will the person who produced it. The second file is the evidence: one row per fact,
 * with the date, the amount as it arrived, the BNR rate that converted it, and the split it came from.
 *
 * ## Built here, not in the browser
 *
 * The figures come from the assessment the engine recorded. A screen rebuilding them from what it happens to
 * have rendered is a second place money is decided, and an export is exactly where that divergence would go
 * unnoticed — nobody checks a downloaded file against the page it came from.
 *
 * ## Amounts are written plainly
 *
 * `1234.56`, dot decimal, no grouping, no currency in the cell. A spreadsheet reads that as a number in every
 * locale; "1.234,56 RON" is text in most of them, and a column of text that looks like money is worse than a
 * column of numbers that needs a header to explain itself. The currency is its own column.
 */
final readonly class AssessmentCsv
{
    /** The figures: one row per rule that ran, with the law it rests on. */
    public function figures(TaxAssessment $assessment): string
    {
        $rows = [[
            'tax', 'category', 'income_ron', 'taxable_base_ron', 'rate', 'tax_ron',
            'explanation_key', 'rule_version', 'source_sections',
        ]];

        $lines = TaxAssessmentLine::query()
            ->where('assessment_id', $assessment->id)
            ->orderBy('tax_code')
            ->orderBy('category_code')
            ->get();

        foreach ($lines as $line) {
            $trace = $line->calculation_trace_json;
            $steps = $trace['steps'] ?? null;
            $first = is_array($steps) ? ($steps[0] ?? null) : null;
            $income = is_array($first) ? ($first['result'] ?? null) : null;
            $refs = $trace['source_refs'] ?? null;

            $rows[] = [
                $line->tax_code,
                $line->category_code,
                is_string($income) ? $this->amountOf($income) : '',
                $this->minorToDecimal($line->taxable_base_minor),
                $line->rate_decimal ?? '',
                $this->minorToDecimal($line->tax_minor),
                $line->explanation_key,
                is_string($trace['rule_version'] ?? null) ? $trace['rule_version'] : '',
                is_array($refs) ? implode(' ', array_filter($refs, 'is_string')) : '',
            ];
        }

        return $this->toCsv($rows);
    }

    /**
     * The evidence: one row per fact, naming the split it came from.
     *
     * No counterparty and no description. Both are encrypted at rest, and writing them into a file that
     * leaves the system turns an export into a decryption — a different act, needing a different permission
     * and its own audit event. The split id is here instead, which reaches the same row inside the app where
     * the access is scoped and recorded.
     */
    public function evidence(TaxAssessment $assessment): string
    {
        $rows = [[
            'recognition_date', 'category', 'amount', 'currency', 'amount_ron',
            'fx_rate', 'fx_rate_date', 'withheld_tax_ron', 'source_type', 'source_id',
        ]];

        $facts = TaxFact::query()
            ->where('tax_subject_id', $assessment->tax_subject_id)
            ->where('fiscal_year', $assessment->fiscal_year)
            ->orderBy('recognition_date')
            ->get();

        foreach ($facts as $fact) {
            $fx = $fact->fx_conversion_json;

            $rows[] = [
                $fact->recognition_date->toDateString(),
                $fact->category_code,
                $this->minorToDecimal($fact->gross_amount_minor),
                $fact->currency_code,
                $this->minorToDecimal($fact->ron_amount_minor),
                is_array($fx) && is_string($fx['rate'] ?? null) ? $fx['rate'] : '',
                is_array($fx) && is_string($fx['rate_date'] ?? null) ? $fx['rate_date'] : '',
                $fact->withheld_tax_ron_minor === null ? '' : $this->minorToDecimal($fact->withheld_tax_ron_minor),
                $fact->source_type,
                $fact->source_id,
            ];
        }

        return $this->toCsv($rows);
    }

    /** The numeric part of a `Money` string, which is how a trace records an amount. */
    private function amountOf(string $money): string
    {
        return preg_match('/^(-?\d+\.\d{2}) [A-Z]{3}$/', $money, $matches) === 1 ? $matches[1] : '';
    }

    private function minorToDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        /*
         * A BOM, because Excel on Windows reads a UTF-8 file without one as the system codepage and turns
         * every Romanian diacritic into mojibake. The file is still valid UTF-8 for everything else.
         */
        fwrite($handle, "\u{FEFF}");

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
