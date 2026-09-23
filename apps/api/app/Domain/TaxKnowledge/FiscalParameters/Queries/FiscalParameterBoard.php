<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Queries;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterRead;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

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

    /** @return array{parameters: list<array<string, mixed>>, years: list<int>} */
    public function __invoke(): array
    {
        $parameters = FiscalParameter::query()->get()->keyBy('code');
        $versions = FiscalParameterVersion::query()
            ->whereIn('fiscal_parameter_id', $parameters->pluck('id'))
            ->where('status', '<>', RuleApprovalStatus::SUPERSEDED->value)
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

        return [
            // The years the law can be read for: from the first the values are modelled for, to the
            // one after this, so a rule is ready before the year it applies to starts.
            'years' => range($this->config->integer('ai.legislation.from_year'), CarbonImmutable::now()->year + 1),
            'parameters' => array_map(function ($definition) use ($parameters, $versions, $reads, $paragraph): array {
                $parameter = $parameters->get($definition->code);

                return [
                    'code' => $definition->code,
                    'type' => $definition->type->value,
                    'subject' => $definition->subject->value,
                    'versions' => $parameter === null ? [] : array_values(($versions->get($parameter->id) ?? collect())->map(static fn (FiscalParameterVersion $version): array => [
                        'id' => $version->id,
                        'status' => $version->status->value,
                        'valid_from' => $version->valid_from->format('Y-m-d'),
                        'valid_until' => $version->valid_until?->format('Y-m-d'),
                        'value' => $version->value_json,
                        'source' => $paragraph($version->source_section_id),
                        'matches' => array_values(array_filter(array_map(
                            static fn (array $match): ?array => ($found = $paragraph($match['section_id'])) === null ? null : [...$found, 'says' => $match['says']],
                            $version->evidence['matches'] ?? [],
                        ))),
                        'readings' => $version->evidence['readings'] ?? [],
                        'checks' => $version->evidence['checks'] ?? [],
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
            }, $this->catalog->all()),
        ];
    }
}
