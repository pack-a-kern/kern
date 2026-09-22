<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Queries;

use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\Exceptions\AmbiguousEffectiveVersionException;
use App\Domain\TaxKnowledge\Exceptions\NoEffectiveVersionFoundException;
use App\Domain\TaxKnowledge\Models\SectionHistory;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceOutlineNode;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;
use App\Domain\TaxKnowledge\Services\LegislationTemporalQuery;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

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
     * The law as it stood on `$asOf`, a page of articles at a time: each top-level provision with
     * everything under it, in reading order.
     *
     * @return array<string, mixed>|null null when no consolidation covers the date
     */
    public function text(string $documentId, DateTimeImmutable $asOf, int $page, int $perPage): ?array
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

        $all = SourceSection::query()
            ->where('document_version_id', $version->id)
            ->orderBy('ordinal')
            ->get(['id', 'parent_id', 'section_type', 'citation_label', 'ordinal', 'text', 'tables', 'amended_on', 'outline_id', 'notes']);
        $headings = $this->headings($version->id);

        $children = [];

        foreach ($all as $section) {
            $children[$section->parent_id ?? ''][] = $section;
        }

        $topLevel = $children[''] ?? [];
        $pageItems = array_slice($topLevel, ($page - 1) * $perPage, $perPage);
        $sections = [];

        foreach ($pageItems as $section) {
            $first = count($sections);
            $this->flatten($section, $children, 0, $sections);
            // The headings an article stands under, outermost first — the page shows the ones that
            // change from the article before.
            $sections[$first]['headings'] = self::chain($section->outline_id, $headings);
        }

        return [
            'version_id' => $version->id,
            'effective_from' => $version->effectiveFrom->format('Y-m-d'),
            'effective_until' => $version->effectiveUntil?->format('Y-m-d'),
            'page' => $page,
            'per_page' => $perPage,
            'total' => count($topLevel),
            'sections' => $sections,
        ];
    }

    /**
     * The table of contents of the law as it stood on `$asOf`: every heading, with the first and last
     * article under it and the page of the text it opens on.
     *
     * @return array<string, mixed>|null null when no consolidation covers the date
     */
    public function outline(string $documentId, DateTimeImmutable $asOf, int $perPage): ?array
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
        $topLevel = SourceSection::query()
            ->where('document_version_id', $version->id)
            ->whereNull('parent_id')
            ->orderBy('ordinal')
            ->get(['citation_label', 'outline_id']);

        /** @var array<string, array{first: int, first_citation: string, last_citation: string}> $spans */
        $spans = [];

        foreach ($topLevel->values() as $position => $section) {
            // An article counts for its own heading and every heading above it.
            foreach (self::chain($section->outline_id, $headings) as $heading) {
                $spans[$heading['id']] ??= ['first' => $position, 'first_citation' => $section->citation_label, 'last_citation' => $section->citation_label];
                $spans[$heading['id']]['last_citation'] = $section->citation_label;
            }
        }

        return [
            'version_id' => $version->id,
            'effective_from' => $version->effectiveFrom->format('Y-m-d'),
            'headings' => array_values(array_map(static fn (array $heading): array => [
                ...$heading,
                'page' => isset($spans[$heading['id']]) ? intdiv($spans[$heading['id']]['first'], $perPage) + 1 : null,
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
     * @param  array<string, list<SourceSection>>  $children
     * @param  list<array<string, mixed>>  $out
     */
    private function flatten(SourceSection $section, array $children, int $depth, array &$out): void
    {
        $out[] = [
            'id' => $section->id,
            'type' => $section->section_type->value,
            'citation' => $section->citation_label,
            'label' => self::label($section->section_type, $section->citation_label),
            'depth' => $depth,
            'text' => $section->text,
            'tables' => $section->tables ?? [],
            'amended_on' => $section->amended_on?->format('Y-m-d'),
            'notes' => $section->notes ?? [],
        ];

        foreach ($children[$section->id] ?? [] as $child) {
            $this->flatten($child, $children, $depth + 1, $out);
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
