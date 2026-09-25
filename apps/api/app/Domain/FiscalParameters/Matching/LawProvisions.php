<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters\Matching;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Exceptions\AmbiguousEffectiveVersionException;
use Kern\Legislation\Exceptions\NoEffectiveVersionFoundException;
use Kern\Legislation\Models\SectionHistory;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceOutlineNode;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Parsing\SectionText;
use Kern\Legislation\Services\LegislationTemporalQuery;

/**
 * The law a fiscal value is read from, as it stood on a day.
 *
 * The law is `fiscal_parameters.law`, in the consolidation in force that day — with every provision
 * whose wording the portal's note dates from before it ({@see \Kern\Legislation\Services\BuildSectionHistory})
 * read as that wording instead. The portal publishes no consolidation of the Codul fiscal from
 * December 2016 to 2021, so on 1 January 2018 the consolidation is 2016's, saying 16%; the income
 * tax's paragraph, read in 2021 and noted "(la 01-01-2018, …)", says 10% from that day.
 *
 * Only the part a value belongs to is given out — "Titlul V Capitolul III", compared as whole words
 * of the table of contents, so Chapter II is never Chapter III.
 *
 * Kept for the run: a version is walked once however many values are read from it.
 */
final class LawProvisions
{
    /**
     * Longest a first line can be and still be an article's title rather than its text. The law
     * writes long ones: Art. 170's is 163 characters, and naming every income it covers is exactly
     * what makes it worth reading.
     */
    private const int TITLE = 280;

    /** @var array<string, list<Provision>> version id => its provisions, in reading order */
    private array $provisions = [];

    private ?string $lawId = null;

    public function __construct(
        private readonly LegislationTemporalQuery $temporal,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * The provisions of one part of the law, in reading order, as they stood on a day.
     *
     * @return list<Provision> empty when the law is not read in, or no consolidation covers the day
     */
    public function inForce(string $within, DateTimeImmutable $on): array
    {
        $lawId = $this->lawId();

        if ($lawId === null) {
            return [];
        }

        try {
            $version = $this->temporal->versionInForceOn($lawId, $on);
        } catch (NoEffectiveVersionFoundException|AmbiguousEffectiveVersionException) {
            return [];
        }

        $path = SectionText::searchable($within);

        return array_values(array_filter(
            $this->noteDated($lawId, $version->id, $on),
            static fn (Provision $provision): bool => $provision->path === $path || str_starts_with($provision->path, $path . ' '),
        ));
    }

    /**
     * The days between two, the law's wording changed on: a consolidation's, or a wording's the
     * portal's note dates. The first day itself is never one of them.
     *
     * @return list<DateTimeImmutable> in order
     */
    public function changeDays(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $lawId = $this->lawId();

        if ($lawId === null) {
            return [];
        }

        $between = [$from->format('Y-m-d'), $until->format('Y-m-d')];
        $days = [
            ...SourceDocumentVersion::query()->where('source_document_id', $lawId)->whereBetween('effective_from', $between)->pluck('effective_from')->all(),
            ...SectionHistory::query()->where('source_document_id', $lawId)->where('dated_by_note', true)->whereBetween('valid_from', $between)->pluck('valid_from')->all(),
        ];
        $onwards = array_values(array_unique(array_map(
            static fn (DateTimeInterface $day): string => $day->format('Y-m-d'),
            array_filter($days, static fn (mixed $day): bool => $day instanceof DateTimeInterface),
        )));
        sort($onwards);

        return array_values(array_map(
            static fn (string $day): DateTimeImmutable => new DateTimeImmutable($day),
            array_filter($onwards, static fn (string $day): bool => $day > $between[0]),
        ));
    }

    /**
     * The days between two that one part of the law changed on: a wording the portal's note dates
     * from that day, or a consolidation that left the part reading differently.
     *
     * The whole law changes on a hundred days a year, and a rule is read again on each of them when
     * the first of January gave nothing. Its own part changes on one or two: Titlul V Capitolul III
     * changed twice in 2018 and not at all in 2023, where the eleven consolidations of that year
     * left it as it was.
     *
     * @param  list<string>  $withins  parts of the law, from its table of contents
     * @return list<DateTimeImmutable> in order
     */
    public function changeDaysWithin(array $withins, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $lawId = $this->lawId();

        if ($lawId === null || $withins === []) {
            return [];
        }

        $citations = [];

        foreach ($withins as $within) {
            foreach ($this->inForce($within, $from) as $provision) {
                $citations[] = $provision->citation;
            }
        }

        $between = [$from->format('Y-m-d'), $until->format('Y-m-d')];
        $noted = SectionHistory::query()
            ->where('source_document_id', $lawId)
            ->where('dated_by_note', true)
            ->whereBetween('valid_from', $between)
            ->whereIn('citation_label', array_values(array_unique($citations)))
            ->pluck('valid_from')
            ->all();

        $days = array_values(array_unique(array_map(
            static fn (DateTimeInterface $day): string => $day->format('Y-m-d'),
            array_filter($noted, static fn (mixed $day): bool => $day instanceof DateTimeInterface),
        )));

        // A consolidation counts only where it left the part reading differently.
        foreach ($this->changeDays($from, $until) as $day) {
            if (! in_array($day->format('Y-m-d'), $days, true) && $this->reads($withins, $day) !== $this->reads($withins, $day->modify('-1 day'))) {
                $days[] = $day->format('Y-m-d');
            }
        }

        sort($days);

        return array_values(array_map(
            static fn (string $day): DateTimeImmutable => new DateTimeImmutable($day),
            array_filter($days, static fn (string $day): bool => $day > $between[0]),
        ));
    }

    /**
     * What one part of the law is, on a day: the provisions in force in it, by what they say.
     *
     * @param  list<string>  $withins
     */
    private function reads(array $withins, DateTimeImmutable $on): string
    {
        $text = '';

        foreach ($withins as $within) {
            foreach ($this->inForce($within, $on) as $provision) {
                $text .= $provision->citation . $provision->text;
            }
        }

        return md5($text);
    }

    /**
     * The days between two that one provision's own wording changed on, by its citation — the law
     * can change during a year, and a value holds only as long as the sentence it rests on does.
     *
     * @return list<DateTimeImmutable> in order
     */
    public function changeDaysOf(string $citation, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $lawId = $this->lawId();

        if ($lawId === null) {
            return [];
        }

        $days = SectionHistory::query()
            ->where('source_document_id', $lawId)
            ->where('citation_label', $citation)
            ->whereBetween('valid_from', [$from->format('Y-m-d'), $until->format('Y-m-d')])
            ->orderBy('valid_from')
            ->pluck('valid_from')
            ->all();

        return array_values(array_map(
            static fn (DateTimeInterface $day): DateTimeImmutable => new DateTimeImmutable($day->format('Y-m-d')),
            array_filter($days, static fn (mixed $day): bool => $day instanceof DateTimeInterface && $day->format('Y-m-d') > $from->format('Y-m-d')),
        ));
    }

    /**
     * A version's provisions with, in the place of each, the wording the portal's note dates from
     * before the day where one covers it — with everything that stands under that wording, and
     * without what stood under the one it replaces — then those the note dates in that the
     * consolidation does not have at all.
     *
     * @return list<Provision>
     */
    private function noteDated(string $lawId, string $versionId, DateTimeImmutable $on): array
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

        $inVersion = $this->provisions($versionId);

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
                // The wording and everything under it: a paragraph rewritten by an act is rewritten
                // with its letters and points, and the ones it had before say something else.
                $replacing[$stretch->citation_label] = array_map(
                    static fn (Provision $wording): Provision => new Provision(
                        $wording->sectionId,
                        $wording->citation,
                        $wording->article,
                        $provision->path,
                        $wording->text,
                        $wording->under,
                        $wording->title,
                        $wording->documentVersionId,
                        $stretch->valid_from->format('Y-m-d'),
                    ),
                    self::with($provision, $this->provisions((string) $version)),
                );
            }
        }

        // Only what is replaced takes its old points with it: a wording this consolidation already
        // has replaces nothing, and its paragraph keeps everything under it.
        /** @var list<string> $replaced */
        $replaced = array_values(array_filter(array_keys($replacing), 'is_string'));
        $read = [];

        foreach ($inVersion as $provision) {
            $wording = $replacing[$provision->citation] ?? null;

            if ($wording !== null) {
                $read = [...$read, ...$wording];
                unset($replacing[$provision->citation]);

                continue;
            }

            // What stood under a paragraph that was replaced went with it.
            if (self::under($provision->citation, $replaced)) {
                continue;
            }

            $read[] = $provision;
        }

        return [...$read, ...($replacing === [] ? [] : array_merge(...array_values($replacing)))];
    }

    /**
     * An article's marginal title: the portal prints it as the article's own first line, above its
     * paragraphs — "Cota de contribuție de asigurări sociale de sănătate". It names what the article
     * is about in the law's own words, which is what a question about that value is written in.
     */
    private static function titleOf(SourceSection $article): string
    {
        $first = trim((string) strtok(trim($article->text), "\n"));

        return mb_strlen($first) > self::TITLE ? '' : $first;
    }

    /**
     * A provision and everything under it, in reading order: what stands under a paragraph is part
     * of it — "baza o reprezintă:" and the letters that say the numbers.
     *
     * @param  list<Provision>  $provisions  the version it is read in, in reading order
     * @return list<Provision>
     */
    private static function with(Provision $provision, array $provisions): array
    {
        return array_values(array_filter(
            $provisions,
            static fn (Provision $one): bool => $one->citation === $provision->citation || str_starts_with($one->citation, $provision->citation . ' '),
        ));
    }

    /**
     * Whether a citation stands under one of these — "Art. 100 alin. (1) lit. b)" under
     * "Art. 100 alin. (1)".
     *
     * @param  list<string>  $citations
     */
    private static function under(string $citation, array $citations): bool
    {
        foreach ($citations as $above) {
            if ($citation !== $above && str_starts_with($citation, $above . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Provision> a version's provisions by section id */
    private function indexed(string $versionId): array
    {
        $indexed = [];

        foreach ($this->provisions($versionId) as $provision) {
            $indexed[$provision->sectionId] = $provision;
        }

        return $indexed;
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
     * Every provision of a version with the heading its article stands under, in reading order.
     *
     * @return list<Provision>
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
            ->get(['id', 'parent_id', 'outline_id', 'citation_label', 'text', 'tables']);

        $children = [];

        foreach ($sections as $section) {
            $children[$section->parent_id ?? ''][] = $section;
        }

        $ordered = [];
        // Each provision is written before what stands under it, and carries that as its own text
        // too: a rule the law states across a paragraph and its points is read whole either way.
        $walk = static function (SourceSection $section, string $path, string $title, string $article) use (&$walk, &$ordered, $children, $versionId): string {
            $at = count($ordered);
            $ordered[] = null;
            $under = '';

            foreach ($children[$section->id] ?? [] as $child) {
                $under .= ' ' . $walk($child, $path, $title, $article);
            }

            $text = $section->fullText();
            $ordered[$at] = new Provision($section->id, $section->citation_label, $article, $path, $text, trim($under), $title, $versionId);

            return $text . ' ' . $under;
        };

        foreach ($children[''] ?? [] as $article) {
            $path = $paths[$article->outline_id ?? ''] ?? '';
            $walk($article, SectionText::searchable(is_string($path) ? $path : ''), self::titleOf($article), $article->citation_label);
        }

        /** @var list<Provision> $ordered */

        return $this->provisions[$versionId] = $ordered;
    }
}
