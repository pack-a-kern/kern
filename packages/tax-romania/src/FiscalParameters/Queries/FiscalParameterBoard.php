<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\Platform\Money\Shares;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterRead;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;
use Kern\TaxRomania\FiscalParameters\ParameterCatalog;

/**
 * Every fiscal value as the tax rules screen shows it: each parameter's values period by period —
 * proposed, approved or rejected — with the paragraph it rests on, every other paragraph found to
 * say it, what it was read from and what did not agree; and each D212 file with what it says that
 * the law was not found to say.
 */
final readonly class FiscalParameterBoard
{
    /** How much of a paragraph is shown beside a value: enough to recognise it, the law page has the rest. */
    private const int EXCERPT = 400;

    public function __construct(private ParameterCatalog $catalog, private ConfigRepository $config)
    {
    }

    /**
     * The years the law can be read for: from the first the values are modelled for, to this one —
     * and the next as soon as its law exists.
     *
     * A year's wording is published in the December before it starts: Legea 239/2025 was in the
     * Monitorul Oficial on 15 December 2025, in force from 1 January 2026, so 2026 could be read
     * weeks before it began. Offering the next year before any of it has been published would offer
     * a year whose law is only this year's, still in force for want of anything replacing it, and
     * the values read for it would be a guess dressed as a reading.
     *
     * @return list<int>
     */
    private function years(): array
    {
        $from = $this->config->integer('ai.legislation.from_year');
        $now = CarbonImmutable::now()->year;
        $law = SourceDocument::query()->where('external_identifier', $this->config->string('fiscal_parameters.law'))->value('id');
        $published = is_string($law) && SourceDocumentVersion::query()
            ->where('source_document_id', $law)
            ->whereYear('effective_from', $now + 1)
            ->exists();

        return range($from, $published ? $now + 1 : $now);
    }

    /** @return array{parameters: list<array<string, mixed>>, years: list<int>, summary: array<string, mixed>} */
    public function __invoke(): array
    {
        $parameters = FiscalParameter::query()->get()->keyBy('code');
        $versions = FiscalParameterVersion::query()
            ->whereIn('fiscal_parameter_id', $parameters->pluck('id'))
            ->where('status', '<>', ApprovalStatus::SUPERSEDED->value)
            ->orderBy('valid_from')
            ->get()
            ->groupBy('fiscal_parameter_id');

        $reads = FiscalParameterRead::query()
            ->whereIn('fiscal_parameter_id', $parameters->pluck('id'))
            ->get()
            ->groupBy('fiscal_parameter_id');
        $sectionIds = [];

        foreach ($versions->flatten() as $version) {
            /** @var FiscalParameterVersion $version */
            $sectionIds[] = $version->source_section_id;
            array_push($sectionIds, ...array_column($version->evidence['matches'] ?? [], 'section_id'));
        }

        $sections = SourceSection::query()
            ->with('version:id,source_document_id,effective_from')
            ->whereIn('id', array_values(array_unique($sectionIds)))
            ->get(['id', 'document_version_id', 'citation_label', 'text', 'tables'])
            ->keyBy('id');

        $paragraph = static function (string $id) use ($sections): ?array {
            /** @var SourceSection|null $section */
            $section = $sections->get($id);

            return $section === null ? null : [
                'section_id' => $section->id,
                'citation' => $section->citation_label,
                'law_id' => $section->version?->source_document_id,
                'on' => $section->version?->effective_from?->format('Y-m-d'),
                'excerpt' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $section->fullText())), self::EXCERPT),
            ];
        };

        $board = array_map(function ($definition) use ($parameters, $versions, $reads, $paragraph): array {
            $parameter = $parameters->get($definition->code);

            return [
                'code' => $definition->code,
                'tax' => $definition->tax,
                'type' => $definition->type->value,
                'subject' => $definition->subject->value,
                'versions' => $parameter === null ? [] : array_values(($versions->get($parameter->id) ?? collect())->map(static fn (FiscalParameterVersion $version): array => [
                    'id' => $version->id,
                    'status' => $version->status->value,
                    'valid_from' => $version->valid_from->format('Y-m-d'),
                    'valid_until' => $version->valid_until?->format('Y-m-d'),
                    'value' => $version->value_json,
                    'display' => FiscalValueDisplay::of($definition->type, $version->value_json),
                    'source' => $paragraph($version->source_section_id),
                    'matches' => array_values(array_filter(array_map(
                        static fn (array $match): ?array => ($found = $paragraph($match['section_id'])) === null ? null : [...$found, 'says' => $match['says']],
                        $version->evidence['matches'] ?? [],
                    ))),
                    'readings' => $version->evidence['readings'] ?? [],
                    'checks' => array_map(
                        static fn (array $check): array => [...$check, 'tone' => FiscalValueDisplay::tone(is_string($check['kind'] ?? null) ? $check['kind'] : '')],
                        $version->evidence['checks'] ?? [],
                    ),
                    'approved_at' => $version->approved_at?->toIso8601String(),
                ])->all()),
                // The years read that gave no value, and why — an empty cell says nothing of
                // whether the law was asked.
                'reads' => $parameter === null ? [] : array_values(($reads->get($parameter->id) ?? collect())->map(static fn (FiscalParameterRead $read): array => [
                    'year' => $read->year,
                    'outcome' => $read->outcome->value,
                    'read_at' => $read->read_at->toIso8601String(),
                    ])->all()),
            ];
        }, $this->catalog->all());

        return ['years' => $this->years(), 'parameters' => $board, 'summary' => $this->summary($parameters->all(), $versions->all(), $reads->all())];
    }

    /**
     * What the screen leads with, worked out here: the minimum wage — the value every threshold is a
     * multiple of — and its history in order, each period's bar against the largest; how many values of the
     * other rules wait for review; and every year something was read for, newest first — for them all, and
     * for each tax's own table.
     *
     * @param  array<array-key, FiscalParameter>  $parameters  by code
     * @param  array<array-key, iterable<FiscalParameterVersion>>  $versions  by parameter, by when each applies from
     * @param  array<array-key, iterable<FiscalParameterRead>>  $reads  by parameter
     * @return array{wage: ?string, wage_in_force: ?string, wage_history: list<array{id: string, bar: string}>, rules: int, waiting: int, years: list<int>, years_by_tax: array<string, list<int>>}
     */
    private function summary(array $parameters, array $versions, array $reads): array
    {
        $wage = null;
        $history = [];
        $waiting = 0;
        /** @var array<string, list<int>> $byTax */
        $byTax = [];

        foreach ($this->catalog->all() as $definition) {
            $parameter = $parameters[$definition->code] ?? null;
            $own = $parameter === null ? [] : [...($versions[$parameter->id] ?? [])];

            // The minimum wage is the one rule named after its own tax.
            if ($definition->code === $definition->tax) {
                $wage = $definition->code;
                $history = $own;

                continue;
            }

            $byTax[$definition->tax] ??= [];

            foreach ($own as $version) {
                $waiting += $version->status === ApprovalStatus::CANDIDATE ? 1 : 0;
                $byTax[$definition->tax][] = $version->valid_from->year;
            }

            foreach ($parameter === null ? [] : ($reads[$parameter->id] ?? []) as $read) {
                $byTax[$definition->tax][] = $read->year;
            }
        }

        $newestFirst = static function (array $years): array {
            $years = array_values(array_unique($years));
            rsort($years);

            return $years;
        };
        $bars = Shares::ofLargest(array_map(static fn (FiscalParameterVersion $version): int => is_int($version->value_json['amount_minor'] ?? null) ? $version->value_json['amount_minor'] : 0, $history));

        return [
            'wage' => $wage,
            // The last of its history: the versions are read by when they apply from.
            'wage_in_force' => $history === [] ? null : $history[array_key_last($history)]->id,
            'wage_history' => array_map(static fn (FiscalParameterVersion $version, string $bar): array => ['id' => $version->id, 'bar' => $bar], $history, $bars),
            // The rules besides the wage, each one value a tax is worked out from.
            'rules' => count($this->catalog->all()) - ($wage === null ? 0 : 1),
            'waiting' => $waiting,
            'years' => $newestFirst(array_merge(...array_values($byTax))),
            'years_by_tax' => array_map($newestFirst, $byTax),
        ];
    }
}
