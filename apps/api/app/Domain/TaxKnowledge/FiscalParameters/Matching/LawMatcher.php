<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Matching;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\Exceptions\AmbiguousEffectiveVersionException;
use App\Domain\TaxKnowledge\Exceptions\NoEffectiveVersionFoundException;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterDefinition;
use App\Domain\TaxKnowledge\Models\SectionHistory;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceOutlineNode;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Parsing\SectionText;
use App\Domain\TaxKnowledge\Services\LegislationTemporalQuery;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The paragraphs of the law that say a value — found by reading, never by a model.
 *
 * The law is `fiscal_parameters.law`, in the consolidation in force on the day the value starts. Only
 * the part the value belongs to is read — "Titlul V Capitolul III", compared as whole words of the
 * table of contents, so Chapter II is never Chapter III — and a paragraph says the value when its
 * text, as a search reads it, holds the phrase with the value in it: "cota de contributie … este de
 * 10". A threshold of several numbers is each number looked for — and where the lettered points of
 * one paragraph say them between them, "lit. a) … 12 salarii", "lit. b) … 24 de salarii", the
 * paragraph they stand under is the one that says it.
 *
 * The law in force on a day is its consolidation of that day — with every provision whose wording
 * the portal's note dates from before it ({@see \App\Domain\TaxKnowledge\Services\BuildSectionHistory})
 * read as that wording instead. The portal publishes no consolidation of the Codul fiscal from
 * December 2016 to 2021, so on 1 January 2018 the consolidation is 2016's, saying 16%; the income
 * tax's paragraph, read in 2021 and noted "(la 01-01-2018, …)", says 10% from that day. A value found
 * that way says so.
 *
 * A value holds for a year, and the law can change during it: a wording in force from 23 March 2018
 * — OUG 18/2018's "12 salarii minime" — is what the D212 applies to all of 2018's income. When the law
 * of the first day says none of the numbers, each later day of the period a wording changed on is
 * read in turn, and what the first of them says is found, the day with it.
 *
 * Kept for the run: a version is read once however many values are looked for in it.
 */
final class LawMatcher
{
    /** @var array<string, list<array{id: string, parent_id: string|null, point: bool, citation: string, text: string, path: string}>> version id => its provisions, in reading order */
    private array $provisions = [];

    private ?string $lawId = null;

    public function __construct(
        private readonly LegislationTemporalQuery $temporal,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * @param  list<int>  $values
     * @param  DateTimeImmutable|null  $until  the value's last day; none to read only the first
     * @return list<LawMatch> in reading order; empty when the law in force then says none of them
     */
    public function find(ParameterDefinition $definition, array $values, DateTimeImmutable $on, ?DateTimeImmutable $until = null): array
    {
        if (! $definition->isInLaw() || $values === []) {
            return [];
        }

        $lawId = $this->lawId();

        if ($lawId === null) {
            return [];
        }

        $found = $this->findOn($definition, $values, $lawId, $on);

        foreach ($until === null ? [] : $this->changesBetween($lawId, $on, $until) as $day) {
            if ($found !== []) {
                break;
            }

            $found = array_map(static fn (LawMatch $match): LawMatch => $match->inForceFrom($day->format('Y-m-d')), $this->findOn($definition, $values, $lawId, $day));
        }

        return $found;
    }

    /**
     * The days after `$on`, up to `$until`, the law's wording changed on: a consolidation's, or a
     * wording's the portal's note dates.
     *
     * @return list<DateTimeImmutable> in order
     */
    private function changesBetween(string $lawId, DateTimeImmutable $on, DateTimeImmutable $until): array
    {
        $between = [$on->format('Y-m-d'), $until->format('Y-m-d')];
        $days = [
            ...SourceDocumentVersion::query()->where('source_document_id', $lawId)->where('effective_from', '>', $between[0])->where('effective_from', '<=', $between[1])->pluck('effective_from')->all(),
            ...SectionHistory::query()->where('source_document_id', $lawId)->where('dated_by_note', true)->where('valid_from', '>', $between[0])->where('valid_from', '<=', $between[1])->pluck('valid_from')->all(),
        ];
        $days = array_values(array_unique(array_map(static fn (DateTimeInterface $day): string => $day->format('Y-m-d'), array_filter($days, static fn (mixed $day): bool => $day instanceof DateTimeInterface))));
        sort($days);

        return array_map(static fn (string $day): DateTimeImmutable => new DateTimeImmutable($day), $days);
    }

    /**
     * @param  list<int>  $values
     * @return list<LawMatch>
     */
    private function findOn(ParameterDefinition $definition, array $values, string $lawId, DateTimeImmutable $on): array
    {
        try {
            $version = $this->temporal->versionInForceOn($lawId, $on);
        } catch (NoEffectiveVersionFoundException|AmbiguousEffectiveVersionException) {
            return [];
        }

        $within = SectionText::searchable((string) $definition->lawWithin);
        $phrases = [];

        foreach ($values as $value) {
            $phrases[$value] = '/\b' . str_replace('{value}', preg_quote((string) $value, '/'), (string) $definition->lawSays) . '\b/';
        }

        $found = [];
        $inForce = $this->inForce($lawId, $version->id, $on);

        foreach ($inForce as $provision) {
            if ($provision['path'] !== $within && ! str_starts_with($provision['path'], $within . ' ')) {
                continue;
            }

            $says = array_values(array_filter($values, static fn (int $value): bool => preg_match($phrases[$value], $provision['text']) === 1));

            if ($says !== []) {
                $found[] = new LawMatch($provision['id'], $provision['citation'], $provision['version'], $says, $provision['dated_by_note']);
            }
        }

        return $this->pointsAsTheirParagraph($found, $values, array_column($inForce, null, 'id'));
    }

    /**
     * Lettered points of one paragraph that say a threshold's numbers between them — two or more of
     * them — as the paragraph they stand under, in the place of the first of them.
     *
     * @param  list<LawMatch>  $found
     * @param  list<int>  $values
     * @param  array<string, array{id: string, parent_id: string|null, point: bool, citation: string, text: string, path: string, version: string, dated_by_note: string|null}>  $provisions  by id
     * @return list<LawMatch>
     */
    private function pointsAsTheirParagraph(array $found, array $values, array $provisions): array
    {
        $parentOf = static function (LawMatch $match) use ($provisions): ?string {
            $provision = $provisions[$match->sectionId] ?? null;

            return $provision !== null && $provision['point'] ? $provision['parent_id'] : null;
        };

        $points = [];

        foreach ($found as $match) {
            $parent = $parentOf($match);

            if ($parent !== null) {
                $points[$parent][] = $match->says;
            }
        }

        $foundIds = array_map(static fn (LawMatch $match): string => $match->sectionId, $found);
        $joined = array_filter($points, static fn (array $says, string $parent): bool => isset($provisions[$parent])
            && ! in_array($parent, $foundIds, true)
            && count($says) > 1
            && array_diff($values, array_merge(...$says)) === [], ARRAY_FILTER_USE_BOTH);
        $read = [];

        foreach ($found as $match) {
            $parent = $parentOf($match);

            if ($parent === null || ! isset($joined[$parent])) {
                $read[] = $match;
            } elseif (! isset($read[$parent])) {
                $paragraph = $provisions[$parent];
                $read[$parent] = new LawMatch($paragraph['id'], $paragraph['citation'], $paragraph['version'], $values, $paragraph['dated_by_note']);
            }
        }

        return array_values($read);
    }

    /**
     * The provisions in force on a day, in the consolidation's reading order: each replaced by the
     * wording the portal's note dates from before it where one covers the day, then those the note
     * dates in that the consolidation does not have at all.
     *
     * @return list<array{id: string, parent_id: string|null, point: bool, citation: string, text: string, path: string, version: string, dated_by_note: string|null}>
     */
    private function inForce(string $lawId, string $versionId, DateTimeImmutable $on): array
    {
        $day = $on->format('Y-m-d');
        $noted = SectionHistory::query()
            ->where('source_document_id', $lawId)
            ->where('dated_by_note', true)
            ->where('valid_from', '<=', $day)
            ->where(static function ($query) use ($day): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>=', $day);
            })
            ->get(['citation_label', 'first_section_id', 'valid_from']);

        $inVersion = array_map(static fn (array $provision): array => [...$provision, 'version' => $versionId, 'dated_by_note' => null], $this->provisions($versionId));

        if ($noted->isEmpty()) {
            return $inVersion;
        }

        $readIn = SourceSection::query()->whereIn('id', $noted->pluck('first_section_id'))->pluck('document_version_id', 'id')->all();
        $readFrom = SourceDocumentVersion::query()->whereIn('id', array_values(array_unique(array_filter($readIn, 'is_string'))))->pluck('effective_from', 'id')->all();
        $replacing = [];

        foreach ($noted as $stretch) {
            $version = $readIn[$stretch->first_section_id] ?? null;
            $firstRead = is_string($version) ? ($readFrom[$version] ?? null) : null;

            // Only a consolidation older than the one the wording is read in lacks it: a later one has it.
            if (! $firstRead instanceof DateTimeInterface || $firstRead->format('Y-m-d') <= $day) {
                continue;
            }

            $provision = $this->indexed((string) $version)[$stretch->first_section_id] ?? null;

            if ($provision !== null) {
                $replacing[$stretch->citation_label] = [...$provision, 'version' => $version, 'dated_by_note' => $stretch->valid_from->format('Y-m-d')];
            }
        }

        $read = [];

        foreach ($inVersion as $provision) {
            $read[] = $replacing[$provision['citation']] ?? $provision;
            unset($replacing[$provision['citation']]);
        }

        return [...$read, ...array_values($replacing)];
    }

    /** @return array<string, array{id: string, parent_id: string|null, point: bool, citation: string, text: string, path: string}> a version's provisions by id */
    private function indexed(string $versionId): array
    {
        return array_column($this->provisions($versionId), null, 'id');
    }

    private function lawId(): ?string
    {
        if ($this->lawId === null) {
            $id = SourceDocument::query()->where('external_identifier', $this->config->string('fiscal_parameters.law'))->value('id');
            $this->lawId = is_string($id) ? $id : null;
        }

        return $this->lawId;
    }

    /**
     * Every provision of a version with the heading its article stands under, as a search reads both.
     *
     * @return list<array{id: string, parent_id: string|null, point: bool, citation: string, text: string, path: string}>
     */
    private function provisions(string $versionId): array
    {
        if (isset($this->provisions[$versionId])) {
            return $this->provisions[$versionId];
        }

        $paths = SourceOutlineNode::query()->where('document_version_id', $versionId)->pluck('path', 'id')->all();
        $sections = SourceSection::query()
            ->where('document_version_id', $versionId)
            ->orderBy('ordinal')
            ->get(['id', 'parent_id', 'outline_id', 'section_type', 'citation_label', 'text', 'tables']);

        $children = [];

        foreach ($sections as $section) {
            $children[$section->parent_id ?? ''][] = $section;
        }

        $ordered = [];
        $walk = static function (SourceSection $section, string $path) use (&$walk, &$ordered, $children): void {
            $ordered[] = [
                'id' => $section->id,
                'parent_id' => $section->parent_id,
                'point' => in_array($section->section_type, [SectionType::LITERA, SectionType::PUNCT], true),
                'citation' => $section->citation_label,
                'text' => SectionText::searchable($section->fullText()),
                'path' => $path,
            ];

            foreach ($children[$section->id] ?? [] as $child) {
                $walk($child, $path);
            }
        };

        foreach ($children[''] ?? [] as $article) {
            $path = $paths[$article->outline_id ?? ''] ?? '';
            $walk($article, SectionText::searchable(is_string($path) ? $path : ''));
        }

        return $this->provisions[$versionId] = $ordered;
    }
}
