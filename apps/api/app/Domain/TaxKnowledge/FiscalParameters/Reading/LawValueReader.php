<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Reading;

use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawMatch;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawProvisions;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\Provision;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCheck;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterDefinition;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Parsing\SectionText;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Every value the law states, year by year, read from the law itself.
 *
 * For one value and one year: the part of the Codul fiscal it belongs to, as it stood on 1 January,
 * narrowed to the paragraphs closest to the value's question, read by a model, and checked back
 * against the paragraph quoted ({@see LawReader}). Two models read, and where they differ the
 * value is still proposed — the first reader's — saying so.
 *
 * A value holds as long as the sentence it rests on does: where that paragraph's wording changes
 * during the year, the law is read again from that day, and the year carries two values rather than
 * one — dividends were taxed 10% until 31 July 2025 and 16% after it.
 *
 * Nothing here decides anything: what comes out is a proposal with its paragraph, its quote and the
 * model that read it, for a person to approve.
 */
final readonly class LawValueReader
{
    /** How much of a word is its stem, for telling "veniturile" and "veniturilor" apart from the rest. */
    private const int STEM = 6;

    public function __construct(
        private ParameterCatalog $catalog,
        private LawProvisions $law,
        private LawReader $reader,
        private ConfigRepository $config,
    ) {
    }

    /**
     * @param  int|null  $onlyYear  one year to read; null for every year the law is read for
     * @return list<array{reading: Reading, matches: list<LawMatch>}>
     */
    public function read(?int $onlyYear = null): array
    {
        if (! $this->reader->isAvailable()) {
            return [];
        }

        $years = $onlyYear !== null
            ? [$onlyYear]
            : range($this->config->integer('ai.legislation.from_year'), CarbonImmutable::now()->year + 1);
        $found = [];

        foreach ($this->catalog->all() as $definition) {
            if (! $definition->isInLaw()) {
                continue;
            }

            $approvedOn = $this->approvedCitations($definition);

            foreach ($years as $year) {
                $found = [...$found, ...$this->readYear($definition, $year, $approvedOn)];
            }
        }

        return $found;
    }

    /**
     * One value for one year — or two, where the paragraph it rests on was rewritten during it.
     *
     * @param  list<string>  $approvedOn  the paragraphs a person approved this value on, for any year
     * @return list<array{reading: Reading, matches: list<LawMatch>}>
     */
    private function readYear(ParameterDefinition $definition, int $year, array $approvedOn): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $until = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        $first = $this->readOn($definition, $from, $approvedOn);

        if ($first === null) {
            return [];
        }

        $stretches = [[$from, $first]];

        foreach ($this->law->changeDaysOf($first[0]->citation, $from, $until) as $day) {
            $again = $this->readOn($definition, $day, $approvedOn);

            if ($again !== null) {
                $stretches[] = [$day, $again];
            }
        }

        $read = [];

        foreach ($stretches as $index => [$starts, [$answer, $provision, $second]]) {
            $next = $stretches[$index + 1][0] ?? null;
            $ends = $next instanceof DateTimeImmutable ? $next->modify('-1 day') : $until;

            // The same value read again from the day its wording changed is the same value: the
            // sentence was rewritten, not the rule.
            if ($index > 0 && $answer->values === $stretches[$index - 1][1][0]->values) {
                continue;
            }

            $checks = $answer->agreesWith($second) || $second === null ? [] : [[
                'kind' => ParameterCheck::READERS_DISAGREE->value,
                'model' => $second->model,
                'citation' => $second->citation,
                'numbers' => implode(', ', $second->values),
            ]];

            $read[] = [
                'reading' => new Reading(
                    $definition->code,
                    $starts,
                    $ends,
                    $definition->value($answer->values, $this->config->string('fiscal_parameters.currency')),
                    $answer->values,
                    ['kind' => 'law', 'model' => $answer->model, 'citation' => $answer->citation, 'quote' => $answer->quote],
                    $checks,
                ),
                'matches' => [new LawMatch(
                    $answer->sectionId,
                    $answer->citation,
                    $provision->documentVersionId,
                    $answer->values,
                    $provision->datedByNote,
                    $index > 0 ? $starts->format('Y-m-d') : null,
                )],
            ];
        }

        return $read;
    }

    /**
     * What the law says on one day: the paragraphs nearest the question, read by both readers.
     *
     * @param  list<string>  $approvedOn
     * @return array{LawAnswer, Provision, LawAnswer|null}|null
     */
    private function readOn(ParameterDefinition $definition, DateTimeImmutable $on, array $approvedOn): ?array
    {
        $nearest = $this->nearest($this->law->inForce((string) $definition->lawWithin, $on), (string) $definition->question, $approvedOn);
        $answers = [];

        foreach ($this->reader->readers() as $model) {
            $answers[] = $this->reader->read($definition, $nearest, $on, $model);
        }

        $answers = array_values(array_filter($answers));
        $answer = $answers[0] ?? null;

        if ($answer === null) {
            return null;
        }

        foreach ($nearest as $provision) {
            if ($provision->sectionId === $answer->sectionId) {
                return [$answer, $provision, $answers[1] ?? null];
            }
        }

        return null;
    }

    /**
     * The paragraphs a question is most likely answered in: those holding the most of its own words,
     * with the ones a person already approved this value on always among them.
     *
     * A word counts by its stem, not whole: Romanian inflects every noun a law and a question have
     * in common — "veniturile" in the question, "veniturilor" in the paragraph — and whole-word
     * matching scores those at nothing. Where a question shares no word with any paragraph, the part
     * is read from its beginning rather than not at all.
     *
     * @param  list<Provision>  $provisions
     * @param  list<string>  $approvedOn
     * @return list<Provision> in reading order
     */
    private function nearest(array $provisions, string $question, array $approvedOn): array
    {
        $stems = array_values(array_unique(array_map(
            static fn (string $word): string => mb_substr($word, 0, self::STEM),
            array_filter(explode(' ', SectionText::searchable($question)), static fn (string $word): bool => mb_strlen($word) >= self::STEM),
        )));
        $scored = [];

        foreach ($provisions as $position => $provision) {
            $text = SectionText::searchable($provision->text);
            $score = count(array_filter($stems, static fn (string $stem): bool => str_contains($text, $stem)));
            $scored[] = ['position' => $position, 'score' => in_array($provision->citation, $approvedOn, true) ? $score + count($stems) : $score];
        }

        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['position']] <=> [$a['score'], $b['position']]);
        $closest = array_filter($scored, static fn (array $entry): bool => $entry['score'] > 0);
        $keep = array_map(
            static fn (array $entry): int => $entry['position'],
            array_slice($closest === [] ? $scored : $closest, 0, $this->config->integer('ai.legislation.paragraphs')),
        );
        sort($keep);

        return array_map(static fn (int $position): Provision => $provisions[$position], $keep);
    }

    /**
     * The paragraphs a person approved this value on, whatever the year: the law is read afresh, but
     * a rule that already rests on a paragraph is looked for there first.
     *
     * @return list<string>
     */
    private function approvedCitations(ParameterDefinition $definition): array
    {
        return array_values(array_unique(FiscalParameterVersion::query()
            ->join('ext_source_sections', 'ext_source_sections.id', '=', 'fiscal_parameter_versions.source_section_id')
            ->whereIn('fiscal_parameter_versions.fiscal_parameter_id', FiscalParameter::query()->where('code', $definition->code)->select('id'))
            ->pluck('ext_source_sections.citation_label')
            ->filter(static fn (mixed $citation): bool => is_string($citation))
            ->all()));
    }
}
