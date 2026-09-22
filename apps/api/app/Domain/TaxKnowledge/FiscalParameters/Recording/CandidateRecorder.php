<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Recording;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawMatch;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCheck;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\Reading;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Readings made candidates, for a person to approve.
 *
 * The same value for the same period is one candidate however many sources say it — its evidence
 * lists them all. A candidate's source is the paragraph that says it: its decision's for the minimum
 * wage, otherwise the first paragraph of the law found, the others kept beside it. A reading the law
 * does not say is never recorded (`docs/AGENTS.md` rule 3: no value without its source); the caller
 * keeps it for a person to see.
 *
 * Run again, it changes nothing that was decided: a value approved or rejected is not proposed
 * again, and an approved one is never touched. Candidates of the parameters read that are no longer
 * read go, so a corrected pattern leaves no stale proposal behind.
 */
final readonly class CandidateRecorder
{
    public function __construct(private ParameterCatalog $catalog)
    {
    }

    /**
     * @param  list<array{reading: Reading, matches: list<LawMatch>}>  $found  every reading with what the law says
     * @param  list<string>  $replacing  the parameters whose other candidates this run replaces; none to only add
     * @return int the candidates there are now for these readings
     */
    public function record(array $found, array $replacing): int
    {
        $groups = [];

        foreach ($found as $item) {
            $groups[$item['reading']->key()][] = $item;
        }

        // Different values for the same parameter and period: each one says so.
        $values = [];

        foreach ($groups as $group) {
            $first = $group[0]['reading'];
            $values[$first->parameterCode . '|' . $first->validFrom->format('Y-m-d')][] = $first->value;
        }

        return DB::transaction(function () use ($groups, $values, $replacing): int {
            $kept = [];

            foreach ($groups as $group) {
                $reading = $group[0]['reading'];
                $matches = $group[0]['matches'];
                $source = $reading->sourceSectionId ?? ($matches[0]->sectionId ?? null);

                if ($source === null) {
                    continue;
                }

                $checks = array_merge(...array_map(static fn (array $item): array => $item['reading']->checks, $group));

                if (count($matches) > 1) {
                    $checks[] = ['kind' => ParameterCheck::SEVERAL_PARAGRAPHS->value];
                }

                if (count($values[$reading->parameterCode . '|' . $reading->validFrom->format('Y-m-d')]) > 1) {
                    $checks[] = ['kind' => ParameterCheck::CONFLICTING->value];
                }

                $version = $this->candidate($reading, $source, [
                    'readings' => array_map(static fn (array $item): array => $item['reading']->origin, $group),
                    'matches' => array_map(static fn (LawMatch $match): array => $match->toArray(), $matches),
                    'checks' => array_values(array_unique($checks, SORT_REGULAR)),
                ]);

                if ($version !== null) {
                    $kept[] = $version->id;
                }
            }

            if ($replacing !== []) {
                FiscalParameterVersion::query()
                    ->where('status', RuleApprovalStatus::CANDIDATE->value)
                    ->whereIn('fiscal_parameter_id', FiscalParameter::query()->whereIn('code', $replacing)->select('id'))
                    ->whereNotIn('id', $kept)
                    ->delete();
            }

            return count($kept);
        });
    }

    /**
     * The candidate for this value and period, made or brought up to date — or null when a person
     * already decided it.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function candidate(Reading $reading, string $source, array $evidence): ?FiscalParameterVersion
    {
        $definition = $this->catalog->find($reading->parameterCode)
            ?? throw new InvalidArgumentException("No parameter {$reading->parameterCode} is configured.");

        $parameter = FiscalParameter::query()->firstOrCreate(['code' => $definition->code], ['value_type' => $definition->type->value]);

        $same = FiscalParameterVersion::query()
            ->where('fiscal_parameter_id', $parameter->id)
            ->where('valid_from', $reading->validFrom->format('Y-m-d'))
            ->get()
            ->filter(static fn (FiscalParameterVersion $version): bool => $version->value_json === $reading->value
                && $version->valid_until?->format('Y-m-d') === $reading->validUntil?->format('Y-m-d'));

        if ($same->contains(static fn (FiscalParameterVersion $version): bool => in_array($version->status, [RuleApprovalStatus::APPROVED, RuleApprovalStatus::REJECTED], true))) {
            return null;
        }

        /** @var FiscalParameterVersion $version */
        $version = $same->first(static fn (FiscalParameterVersion $version): bool => $version->status === RuleApprovalStatus::CANDIDATE)
            ?? new FiscalParameterVersion([
                'fiscal_parameter_id' => $parameter->id,
                'valid_from' => $reading->validFrom->format('Y-m-d'),
                'valid_until' => $reading->validUntil?->format('Y-m-d'),
                'value_json' => $reading->value,
                'status' => RuleApprovalStatus::CANDIDATE->value,
            ]);

        $version->fill(['source_section_id' => $source, 'evidence' => $evidence])->save();

        return $version;
    }
}
