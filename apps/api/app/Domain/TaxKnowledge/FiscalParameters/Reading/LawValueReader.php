<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Reading;

use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
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
 * A model is asked only where an answer can still change something. A year a person has decided is
 * not read again — the answer would be thrown away — nor is one whose paragraph stands unchanged
 * since it was last read. The second reader is asked only where the first says something new, which
 * is where a misreading would otherwise pass.
 *
 * Nothing here decides anything: what comes out is a proposal with its paragraph, its quote and the
 * model that read it, for a person to approve.
 */
final readonly class LawValueReader
{
    /** How much of a word is its stem, for telling "veniturile" and "veniturilor" apart from the rest. */
    private const int STEM = 6;

    /** The shortest word worth weighing: "cota" and "155" say more than any long word around them. */
    private const int WORD = 3;

    public function __construct(
        private ParameterCatalog $catalog,
        private LawProvisions $law,
        private LawReader $reader,
        private ConfigRepository $config,
    ) {
    }

    /**
     * Each value as it is read, rather than the year at the end of it: a value takes a model a
     * minute, and what is read is worth keeping before the next one is asked.
     *
     * @param  int|null  $onlyYear  one year to read; null for every year the law is read for
     * @return iterable<array{reading: Reading, matches: list<LawMatch>}>
     */
    public function read(?int $onlyYear = null): iterable
    {
        if (! $this->reader->isAvailable()) {
            return;
        }

        $years = $onlyYear !== null
            ? [$onlyYear]
            : range($this->config->integer('ai.legislation.from_year'), CarbonImmutable::now()->year + 1);

        foreach ($this->catalog->all() as $definition) {
            if (! $definition->isInLaw()) {
                continue;
            }

            $approvedOn = $this->approvedCitations($definition);
            $known = $this->known($definition);

            foreach ($years as $year) {
                yield from $this->readYear($definition, $year, $approvedOn, $known);
            }
        }
    }

    /**
     * One value for one year — or two, where the paragraph it rests on was rewritten during it.
     *
     * @param  list<string>  $approvedOn  the paragraphs a person approved this value on, for any year
     * @param  array<string, array{decided: bool, section_id: string|null, values: list<int>}>  $known  what is already held for this value, by the day it starts
     * @return list<array{reading: Reading, matches: list<LawMatch>}>
     */
    private function readYear(ParameterDefinition $definition, int $year, array $approvedOn, array $known): array
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $until = new DateTimeImmutable(sprintf('%04d-12-31', $year));

        if ($this->settled($definition, $from, $known)) {
            return [];
        }

        $first = $this->readOn($definition, $from, $approvedOn, $known);

        if ($first === null) {
            return [];
        }

        $stretches = [[$from, $first]];

        foreach ($this->law->changeDaysOf($first[0]->citation, $from, $until) as $day) {
            $again = $this->readOn($definition, $day, $approvedOn, $known);

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
     * What the law says on one day: the paragraphs nearest the question, read by the first reader,
     * and by the second where the first says something not already held.
     *
     * @param  list<string>  $approvedOn
     * @param  array<string, array{decided: bool, section_id: string|null, values: list<int>}>  $known
     * @return array{LawAnswer, Provision, LawAnswer|null}|null
     */
    private function readOn(ParameterDefinition $definition, DateTimeImmutable $on, array $approvedOn, array $known): ?array
    {
        $nearest = $this->nearest($this->anchored($this->law->inForce((string) $definition->lawWithin, $on), $approvedOn), (string) $definition->question, $approvedOn);
        $readers = $this->reader->readers();
        $answer = $this->reader->read($definition, $nearest, $on, $readers[0] ?? '');

        if ($answer === null) {
            return null;
        }

        $second = isset($readers[1]) && ! $this->alreadyRead($answer, $known)
            ? $this->reader->read($definition, $nearest, $on, $readers[1])
            : null;
        $answers = [$answer, $second];

        foreach ($nearest as $provision) {
            if ($provision->sectionId === $answer->sectionId) {
                return [$answer, $provision, $answers[1] ?? null];
            }
        }

        return null;
    }

    /**
     * Whether a day's value is already settled: a person decided it, or the paragraph it was read in
     * still stands, word for word, where it was read.
     *
     * @param  array<string, array{decided: bool, section_id: string|null, values: list<int>}>  $known
     */
    private function settled(ParameterDefinition $definition, DateTimeImmutable $on, array $known): bool
    {
        $held = $known[$on->format('Y-m-d')] ?? null;

        if ($held === null) {
            return false;
        }

        if ($held['decided']) {
            return true;
        }

        // A provision belongs to one consolidation: the same section still in force is the same
        // words, so the law has not changed since it was read and the answer cannot either.
        foreach ($this->law->inForce((string) $definition->lawWithin, $on) as $provision) {
            if ($provision->sectionId === $held['section_id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an answer is one this value already holds, which the second reader need not confirm.
     *
     * @param  array<string, array{decided: bool, section_id: string|null, values: list<int>}>  $known
     */
    private function alreadyRead(LawAnswer $answer, array $known): bool
    {
        foreach ($known as $held) {
            if ($held['values'] === $answer->values && $held['section_id'] === $answer->sectionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is already held for a value, by the day each period starts: whether a person decided it,
     * the paragraph it was read in, and its numbers.
     *
     * @return array<string, array{decided: bool, section_id: string|null, values: list<int>}>
     */
    private function known(ParameterDefinition $definition): array
    {
        $held = [];

        foreach (FiscalParameterVersion::query()
            ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', $definition->code)->select('id'))
            ->get(['valid_from', 'value_json', 'status', 'source_section_id']) as $version) {
            $held[$version->valid_from->format('Y-m-d')] = [
                'decided' => in_array($version->status, [RuleApprovalStatus::APPROVED, RuleApprovalStatus::REJECTED], true),
                'section_id' => $version->source_section_id,
                'values' => $definition->lawValues($version->value_json),
            ];
        }

        return $held;
    }

    /**
     * The articles a rule already rests on, where it rests on any: a value approved on Art. 170
     * alin. (4) is that paragraph's rule, and the year it stops saying it is the year the rule ends
     * — not the year some other paragraph of the chapter happens to hold a number like it.
     *
     * The law rewrites a paragraph rather than withdrawing it: Art. 170 alin. (4) held the single
     * base of twelve minimum wages until 2022, the bands in 2023, and something else since. Asked of
     * the whole chapter, a question about the first is answered from the others.
     *
     * @param  list<Provision>  $provisions
     * @param  list<string>  $approvedOn
     * @return list<Provision>
     */
    private function anchored(array $provisions, array $approvedOn): array
    {
        $articles = array_values(array_unique(array_map(
            static fn (Provision $provision): string => $provision->article,
            array_filter($provisions, static fn (Provision $provision): bool => in_array($provision->citation, $approvedOn, true)),
        )));

        return $articles === []
            ? $provisions
            : array_values(array_filter($provisions, static fn (Provision $provision): bool => in_array($provision->article, $articles, true)));
    }

    /**
     * The articles a question is most likely answered in, whole.
     *
     * Whole, because a paragraph is written to be read under its article: "Baza anuală de calcul …
     * o reprezintă:" says nothing on its own, and the numbers stand in the lettered points below it.
     * An article is scored by everything in it, its marginal title counting double — the law's own
     * name for what it is about, "Cota de contribuție de asigurări sociale de sănătate", which is
     * the question almost word for word.
     *
     * A word is worth what it tells apart, not that it is there: "contribuția de asigurări sociale
     * de sănătate" stands in two hundred of Chapter III's paragraphs and separates none of them,
     * while "cota" stands in four. Counting words alone returned the chapter's opening definitions
     * for every question asked of it.
     *
     * A word counts by its stem, since Romanian inflects every noun a law and a question have in
     * common — "veniturile" against "veniturilor" — and short words count like any other: "cota" is
     * four letters and names the value the whole question is about.
     *
     * An article is weighed against its own length: Art. 154 lists sixty exemptions and so holds
     * most words of most questions about health contributions, while answering none of them.
     *
     * @param  list<Provision>  $provisions
     * @param  list<string>  $approvedOn  the paragraphs a person approved this value on
     * @return list<Provision> in reading order
     */
    private function nearest(array $provisions, string $question, array $approvedOn): array
    {
        $stems = array_values(array_unique(array_map(
            static fn (string $word): string => mb_substr($word, 0, self::STEM),
            array_filter(explode(' ', SectionText::searchable($question)), static fn (string $word): bool => mb_strlen($word) >= self::WORD),
        )));
        $articles = [];

        foreach ($provisions as $provision) {
            $articles[$provision->article] ??= ['title' => SectionText::searchable($provision->title), 'texts' => [], 'approved' => false];
            $articles[$provision->article]['texts'][] = SectionText::searchable($provision->text);
            $articles[$provision->article]['approved'] = $articles[$provision->article]['approved'] || in_array($provision->citation, $approvedOn, true);
        }

        $weights = [];

        foreach ($stems as $stem) {
            $in = count(array_filter($articles, static fn (array $article): bool => $article['title'] !== '' && str_contains($article['title'], $stem)
                || array_filter($article['texts'], static fn (string $text): bool => str_contains($text, $stem)) !== []));
            $weights[$stem] = $in === 0 ? 0.0 : log(1 + count($articles) / $in);
        }

        $of = static function (string $text) use ($stems, $weights): float {
            $score = 0.0;

            foreach ($stems as $stem) {
                $score += str_contains($text, $stem) ? $weights[$stem] : 0.0;
            }

            return $score;
        };
        $scored = [];

        foreach ($articles as $citation => $article) {
            // The article's best paragraph, not all of them together: an article listing sixty
            // exemptions holds most words of most questions between them, and answers none.
            $scored[$citation] = ($article['approved'] ? array_sum($weights) : 0.0)
                + $of($article['title']) * 2
                + max(array_map($of, $article['texts']));
        }

        arsort($scored);
        $read = array_slice(array_keys(array_filter($scored, static fn (float $score): bool => $score > 0.0)), 0, $this->config->integer('ai.legislation.articles'));
        $room = $this->config->integer('ai.legislation.paragraphs');
        $kept = [];

        // The nearest article first and whole: an article of a hundred lettered points would
        // otherwise fill the room by itself and leave the one that answers the question outside it.
        foreach ($read as $citation) {
            $article = array_values(array_filter($provisions, static fn (Provision $provision): bool => $provision->article === $citation));

            if (count($article) <= $room) {
                $kept = [...$kept, ...$article];
                $room -= count($article);
            }
        }

        $order = array_map(static fn (Provision $provision): string => $provision->citation, $provisions);
        usort($kept, static fn (Provision $a, Provision $b): int => array_search($a->citation, $order, true) <=> array_search($b->citation, $order, true));

        return $kept;
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
