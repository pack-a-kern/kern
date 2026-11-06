<?php

declare(strict_types=1);

namespace Kern\Legislation\Queries;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\DataTransferObjects\SectionNode;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Exceptions\AmbiguousEffectiveVersionException;
use Kern\Legislation\Exceptions\NoEffectiveVersionFoundException;
use Kern\Legislation\Models\SectionHistory;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceOutlineNode;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Kern\Legislation\Services\LegislationTemporalQuery;

/**
 * The laws this installation has read, as the legislation page shows them.
 *
 * Read-only, and arrays shaped for the page: which laws, their consolidations, a law's text as it
 * stood on a date, and one provision's history. Public law, not a taxpayer's data — nothing here is
 * scoped to a subject.
 */
final readonly class LegislationLibrary
{
    private const string SOURCE = 'LEGISLATIE';

    /** Sections whose wording is read per query: a part of a law is rarely more, and the whole law is a few queries. */
    private const int WORDING_BATCH = 1000;

    public function __construct(
        private OfficialSourceRepository $sources,
        private LegislationTemporalQuery $temporal,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function laws(): array
    {
        $sourceId = $this->sources->findIdForCode(self::SOURCE);

        if ($sourceId === null) {
            return [];
        }

        $documents = SourceDocument::query()
            ->where('official_source_id', $sourceId)
            ->whereNotNull('act_number')
            ->orderBy('act_date')
            ->get();

        $stats = DB::table('ext_source_document_versions')
            ->whereIn('source_document_id', $documents->pluck('id'))
            ->whereNotNull('consolidation_ref')
            ->groupBy('source_document_id')
            ->selectRaw('source_document_id, count(*) AS consolidations_read, min(effective_from) AS first_from, max(effective_from) AS last_from')
            ->get()
            ->keyBy('source_document_id');

        return array_values($documents->map(function (SourceDocument $document) use ($stats): array {
            $stat = $stats->get($document->id);
            $listed = $document->consolidations ?? [];

            return [
                ...$this->describe($document),
                'consolidations_listed' => count($listed),
                'consolidations_read' => is_numeric($stat?->consolidations_read) ? (int) $stat->consolidations_read : 0,
                'consolidations_failed' => count(array_filter($listed, static fn (array $entry): bool => ($entry['failed'] ?? false) === true)),
                'first_consolidation' => is_string($stat?->first_from) ? $stat->first_from : null,
                'last_consolidation' => is_string($stat?->last_from) ? $stat->last_from : null,
            ];
        })->all());
    }

    /**
     * The laws already read among these portal ids, as portal id => law id — for a search result to
     * say an act is here already.
     *
     * @param  list<string>  $portalIds
     * @return array<string, string>
     */
    public function lawsByPortalId(array $portalIds): array
    {
        $sourceId = $this->sources->findIdForCode(self::SOURCE);

        if ($sourceId === null || $portalIds === []) {
            return [];
        }

        /** @var array<string, string> */
        return SourceDocument::query()
            ->where('official_source_id', $sourceId)
            ->whereIn('portal_document_id', $portalIds)
            ->pluck('id', 'portal_document_id')
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function law(string $documentId): ?array
    {
        $document = $this->document($documentId);

        if ($document === null) {
            return null;
        }

        $versions = SourceDocumentVersion::query()
            ->where('source_document_id', $document->id)
            ->whereNotNull('consolidation_ref')
            ->withCount('sections')
            ->get()
            ->keyBy('consolidation_ref');

        return [
            ...$this->describe($document),
            'consolidations' => array_map(static function (array $entry) use ($versions): array {
                /** @var SourceDocumentVersion|null $version */
                $version = $versions->get($entry['ref']);

                return [
                    'date' => $entry['date'],
                    'ref' => $entry['ref'],
                    'url' => $entry['url'],
                    'failed' => ($entry['failed'] ?? false) === true,
                    'version_id' => $version?->id,
                    'effective_until' => $version?->effective_until?->format('Y-m-d'),
                    'section_count' => is_numeric($version?->getAttribute('sections_count')) ? (int) $version->getAttribute('sections_count') : null,
                ];
            }, array_reverse($document->consolidations ?? [])),
        ];
    }

    /**
     * One part of the law as it stood on `$asOf` — the title, chapter or section a reader opened —
     * with every article under it, each with everything under it, in reading order.
     *
     * A part is named by its path in the table of contents, "Titlul V Capitolul III", which a later
     * consolidation keeps; a provision by its citation, "Art. 64 alin. (1)", which opens the smallest
     * part its article stands under, the provision marked. Neither given, or not in this version: the
     * law's first part. A law with no table of contents is one part.
     *
     * @return array<string, mixed>|null null when no consolidation covers the date
     */
    public function text(string $documentId, DateTimeImmutable $asOf, ?string $heading = null, ?string $provision = null): ?array
    {
        $document = $this->document($documentId);

        if ($document === null) {
            return null;
        }

        try {
            $version = $this->temporal->versionInForceOn($document->id, $asOf);
        } catch (NoEffectiveVersionFoundException|AmbiguousEffectiveVersionException) {
            return null;
        }

        // The whole law's structure, without its wording, as nodes rather than models (`SectionNode`):
        // the Codul fiscal is some 7 000 sections, and a page shows one part of it. The wording is read
        // below, for that part only.
        $all = $this->nodes(SourceSection::query()->where('document_version_id', $version->id));
        $headings = $this->headings($version->id);
        $byId = [];
        $children = [];
        $focus = null;

        foreach ($all as $section) {
            $byId[$section->id] = $section;
            $children[$section->parentId ?? ''][] = $section;

            if ($provision !== null && $focus === null && $section->citation === $provision) {
                $focus = $section;
            }
        }

        $topLevel = $children[''] ?? [];
        $article = $focus;

        while ($article instanceof SectionNode && $article->parentId !== null) {
            $article = $byId[$article->parentId] ?? null;
        }

        $pathIds = array_column($headings, 'id', 'path');
        $part = $article instanceof SectionNode
            ? $article->outlineId
            : ($pathIds[$heading ?? ''] ?? (self::chain(($topLevel[0] ?? null)?->outlineId, $headings)[0]['id'] ?? null));
        $shown = [];

        foreach ($topLevel as $section) {
            $chain = self::chain($section->outlineId, $headings);

            if ($part === null ? $chain !== [] : ! in_array($part, array_column($chain, 'id'), true)) {
                continue;
            }

            $shown[] = [$section, $chain];
        }

        $wording = $this->wording(self::within(array_column($shown, 0), $children));
        $sections = [];

        foreach ($shown as [$section, $chain]) {
            $first = count($sections);
            $this->flatten($section, $children, $wording, 0, $sections);
            // The headings an article stands under, outermost first — the page shows the ones that
            // change from the article before.
            $sections[$first]['headings'] = $chain;
        }

        return [
            'version_id' => $version->id,
            'effective_from' => $version->effectiveFrom->format('Y-m-d'),
            'effective_until' => $version->effectiveUntil?->format('Y-m-d'),
            'heading' => self::chain($part, $headings),
            ...$this->neighbours($part, $headings, $topLevel),
            'focus' => $focus?->id,
            'sections' => $sections,
        ];
    }

    /**
     * The table of contents of the law as it stood on `$asOf`: every heading, with the first and last
     * article under it — none when no article stands under it.
     *
     * @return array<string, mixed>|null null when no consolidation covers the date
     */
    public function outline(string $documentId, DateTimeImmutable $asOf): ?array
    {
        $document = $this->document($documentId);

        if ($document === null) {
            return null;
        }

        try {
            $version = $this->temporal->versionInForceOn($document->id, $asOf);
        } catch (NoEffectiveVersionFoundException|AmbiguousEffectiveVersionException) {
            return null;
        }

        $headings = $this->headings($version->id);
        $spans = $this->spans($headings, $this->nodes(SourceSection::query()
            ->where('document_version_id', $version->id)
            ->whereNull('parent_id')));

        return [
            'version_id' => $version->id,
            'effective_from' => $version->effectiveFrom->format('Y-m-d'),
            'headings' => array_values(array_map(static fn (array $heading): array => [
                ...$heading,
                'first_citation' => $spans[$heading['id']]['first_citation'] ?? null,
                'last_citation' => $spans[$heading['id']]['last_citation'] ?? null,
            ], $headings)),
        ];
    }

    /**
     * Every stretch of one provision's wording, newest first, each with the text it read.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $documentId, string $citation): array
    {
        $rows = SectionHistory::query()
            ->where('source_document_id', $documentId)
            ->where('citation_label', $citation)
            ->orderByDesc('valid_from')
            ->get();

        $texts = SourceSection::query()
            ->whereIn('id', $rows->pluck('first_section_id'))
            ->get(['id', 'text', 'tables', 'notes'])
            ->keyBy('id');

        return array_values($rows->map(static function (SectionHistory $row) use ($texts): array {
            /** @var SourceSection|null $section */
            $section = $texts->get($row->first_section_id);

            return [
                'version_index' => $row->version_index,
                'valid_from' => $row->valid_from->format('Y-m-d'),
                'valid_until' => $row->valid_until?->format('Y-m-d'),
                'amended_on' => $row->amended_on?->format('Y-m-d'),
                'snapshot_count' => $row->snapshot_count,
                'coverage_complete' => $row->coverage_complete,
                'dated_by_note' => $row->dated_by_note,
                'text' => $section->text ?? '',
                'tables' => $section->tables ?? [],
                'notes' => $section->notes ?? [],
            ];
        })->all());
    }

    private function document(string $documentId): ?SourceDocument
    {
        $sourceId = $this->sources->findIdForCode(self::SOURCE);

        return $sourceId === null ? null : SourceDocument::query()
            ->where('official_source_id', $sourceId)
            ->whereNotNull('act_number')
            ->find($documentId);
    }

    /**
     * A version's headings, in reading order, by id.
     *
     * @return array<string, array{id: string, parent_id: string|null, level: string, label: string, name: string, path: string}>
     */
    private function headings(string $versionId): array
    {
        $headings = [];

        foreach (SourceOutlineNode::query()->where('document_version_id', $versionId)->orderBy('position')->get() as $node) {
            $headings[$node->id] = [
                'id' => $node->id,
                'parent_id' => $node->parent_id,
                'level' => $node->level->value,
                'label' => $node->label,
                'name' => $node->name,
                'path' => $node->path,
            ];
        }

        return $headings;
    }

    /**
     * A heading and those above it, outermost first.
     *
     * @param  array<string, array{id: string, parent_id: string|null, level: string, label: string, name: string, path: string}>  $headings
     * @return list<array{id: string, parent_id: string|null, level: string, label: string, name: string, path: string}>
     */
    private static function chain(?string $headingId, array $headings): array
    {
        $chain = [];

        while ($headingId !== null && isset($headings[$headingId])) {
            array_unshift($chain, $headings[$headingId]);
            $headingId = $headings[$headingId]['parent_id'];
        }

        return $chain;
    }

    /**
     * The first and last article under each heading: an article counts for its own heading and every
     * heading above it.
     *
     * @param  array<string, array{id: string, parent_id: string|null, level: string, label: string, name: string, path: string}>  $headings
     * @param  list<SectionNode>  $articles  in reading order
     * @return array<string, array{first_citation: string, last_citation: string}>
     */
    private function spans(array $headings, array $articles): array
    {
        $spans = [];

        foreach ($articles as $article) {
            foreach (self::chain($article->outlineId, $headings) as $heading) {
                $spans[$heading['id']] ??= ['first_citation' => $article->citation, 'last_citation' => $article->citation];
                $spans[$heading['id']]['last_citation'] = $article->citation;
            }
        }

        return $spans;
    }

    /**
     * The parts before and after one, to read on: the headings of the same level, with articles
     * under them, on either side of it. The articles under no heading have none.
     *
     * @param  array<string, array{id: string, parent_id: string|null, level: string, label: string, name: string, path: string}>  $headings
     * @param  list<SectionNode>  $articles  in reading order
     * @return array{previous: array<string, string|null>|null, next: array<string, string|null>|null}
     */
    private function neighbours(?string $part, array $headings, array $articles): array
    {
        $level = $part === null ? null : ($headings[$part]['level'] ?? null);

        if ($level === null) {
            return ['previous' => null, 'next' => null];
        }

        $spans = $this->spans($headings, $articles);
        $same = array_values(array_filter($headings, static fn (array $heading): bool => $heading['level'] === $level && isset($spans[$heading['id']])));
        $at = array_search($part, array_column($same, 'id'), true);

        return [
            'previous' => $at === false ? null : ($same[$at - 1] ?? null),
            'next' => $at === false ? null : ($same[$at + 1] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function describe(SourceDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'type' => $document->document_type,
            'type_label' => $document->act_type_label,
            'number' => $document->act_number,
            'issuer' => $document->issuer,
            'act_date' => $document->act_date?->format('Y-m-d'),
            'official_journal' => $document->official_journal,
            'official_journal_number' => $document->official_journal_number,
            'published_on' => $document->publication_date?->format('Y-m-d'),
            'in_force_from' => $document->in_force_from?->format('Y-m-d'),
            'url' => $document->canonical_url,
            'last_synced_at' => $document->last_synced_at?->toIso8601String(),
        ];
    }

    /**
     * Sections as nodes, in reading order: rows read straight into `SectionNode`, never hydrated as models.
     *
     * @param  Builder<SourceSection>  $query
     * @return list<SectionNode>
     */
    private function nodes(Builder $query): array
    {
        return array_values($query->orderBy('ordinal')->toBase()->get(SectionNode::COLUMNS)
            ->map(SectionNode::fromRow(...))
            ->all());
    }

    /**
     * The ids of these sections and of everything under them.
     *
     * @param  list<SectionNode>  $sections
     * @param  array<string, list<SectionNode>>  $children
     * @return list<string>
     */
    private static function within(array $sections, array $children): array
    {
        $ids = [];

        while ($sections !== []) {
            $section = array_pop($sections);
            $ids[] = $section->id;
            array_push($sections, ...($children[$section->id] ?? []));
        }

        return $ids;
    }

    /**
     * The wording of these sections — text, tables and notes — a batch of ids per query.
     *
     * @param  list<string>  $ids
     * @return array<string, SourceSection> by id
     */
    private function wording(array $ids): array
    {
        $wording = [];

        foreach (array_chunk($ids, self::WORDING_BATCH) as $batch) {
            foreach (SourceSection::query()->whereKey($batch)->get(['id', 'text', 'tables', 'notes']) as $section) {
                $wording[$section->id] = $section;
            }
        }

        return $wording;
    }

    /**
     * @param  array<string, list<SectionNode>>  $children
     * @param  array<string, SourceSection>  $wording  by id, from {@see wording()}
     * @param  list<array<string, mixed>>  $out
     */
    private function flatten(SectionNode $section, array $children, array $wording, int $depth, array &$out): void
    {
        $words = $wording[$section->id];

        $out[] = [
            'id' => $section->id,
            'type' => $section->type->value,
            'citation' => $section->citation,
            'label' => self::label($section->type, $section->citation),
            'depth' => $depth,
            'text' => $words->text,
            'tables' => $words->tables ?? [],
            // As the model's `date:Y-m-d` cast read it, whichever way the driver returns the column.
            'amended_on' => $section->amendedOn === null ? null : CarbonImmutable::parse($section->amendedOn)->format('Y-m-d'),
            'notes' => $words->notes ?? [],
        ];

        foreach ($children[$section->id] ?? [] as $child) {
            $this->flatten($child, $children, $wording, $depth + 1, $out);
        }
    }

    /**
     * How a provision is headed where it stands in the law — "Articolul 170", "(2)", "a)", "–" —
     * from its citation, whose last part is its own label.
     */
    private static function label(SectionType $type, string $citation): string
    {
        return match ($type) {
            SectionType::ARTICOL => (string) preg_replace('/^Art\.\s*/u', 'Articolul ', $citation),
            SectionType::ALINEAT => preg_match('/alin\.\s*(\S+)$/u', $citation, $m) === 1 ? $m[1] : $citation,
            SectionType::LITERA => preg_match('/lit\.\s*(\S+)$/u', $citation, $m) === 1 ? $m[1] : $citation,
            SectionType::PUNCT => preg_match('/pct\.\s*(\S+)$/u', $citation, $m) === 1 ? $m[1] : $citation,
            SectionType::LINIUTA => '–',
            SectionType::ANEXA => $citation,
        };
    }
}
