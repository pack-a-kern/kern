<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Services;

use App\Domain\TaxKnowledge\DataTransferObjects\LegislationParsingResult;
use App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus;
use App\Domain\TaxKnowledge\Exceptions\UnknownSourceDocumentVersionException;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceOutlineNode;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser;
use App\Domain\TaxKnowledge\Parsing\ParsedLegislationDocument;
use App\Domain\TaxKnowledge\Parsing\ParsedSection;
use App\Domain\TaxKnowledge\Parsing\SectionText;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns one {@see ParsedLegislationDocument} into persisted `ext_source_sections` rows for a given
 * `ext_source_document_versions` row, and updates that version's parsing bookkeeping columns.
 *
 * **Re-parsing an already-parsed version is supported and safe**, inside one transaction. A
 * provision keeps its id across a re-parse — the same version, the same citation — and is updated in
 * place; only what the new parse no longer has is removed. A fiscal value approved against "Art. 170
 * alin. (1)" points at that row, so a better parser never cuts the link to the law, and a provision
 * that vanished while a value still cites it stops the re-parse rather than leaving the value
 * pointing at nothing. This is *not* "overwriting a raw
 * official source" (`docs/AGENTS.md` rule 17) — the raw bytes in
 * `App\Domain\TaxKnowledge\Storage\RawSourceObjectStore` and the `ext_source_document_versions` row's
 * own identity/hash are never touched; `ext_source_sections` is a derived, recomputable index of that
 * immutable content, and re-deriving it under a newer `parser_version` is exactly what should
 * happen when the parser improves.
 *
 * **Effective dating is never inferred here.** `$effectiveFrom`/`$effectiveUntil` default to
 * `null` and are persisted exactly as given — this method never looks at parsed text to guess a
 * date (`docs/AGENTS.md` §2). The resulting `status` is computed purely from whether
 * `$effectiveFrom` ended up non-null:
 * {@see SourceDocumentVersionStatus::PARSED} if so, {@see SourceDocumentVersionStatus::REQUIRES_REVIEW}
 * otherwise. The page itself carries no in-force date; the portal's list of consolidations does,
 * and `SyncLegislationDocument` passes each consolidation's date in. A version reached any other way
 * stays `REQUIRES_REVIEW`.
 *
 * What a provision's notes say about when it applies ({@see NoteReader}) is stored with the note,
 * each date beside the words it was read from — a statement for a person or a rule to weigh, never
 * written into the section's or the version's own dates.
 */
final readonly class LegislationSectionPersister
{
    private const int INSERT_BATCH = 1000;

    public function __construct(
        private NoteReader $notes,
    ) {
    }

    public function persist(
        string $sourceDocumentVersionId,
        ParsedLegislationDocument $parsed,
        string $parserVersion = LegislatieJustRoHtmlParser::VERSION,
        ?DateTimeImmutable $effectiveFrom = null,
        ?DateTimeImmutable $effectiveUntil = null,
    ): LegislationParsingResult {
        /** @var SourceDocumentVersion|null $version */
        $version = SourceDocumentVersion::query()->find($sourceDocumentVersionId);

        if ($version === null) {
            throw UnknownSourceDocumentVersionException::forId($sourceDocumentVersionId);
        }

        $now = now('UTC');
        $outlineIds = [];
        $outlineRows = [];

        // Parents come before their children in the parsed outline, so each parent's id is known.
        foreach ($parsed->outline as $index => $node) {
            $outlineIds[$index] = (string) Str::uuid7();
            $outlineRows[] = [
                'id' => $outlineIds[$index],
                'document_version_id' => $version->id,
                'parent_id' => $node->parentIndex === null ? null : $outlineIds[$node->parentIndex],
                'level' => $node->level->value,
                'label' => $node->label,
                'name' => $node->name,
                'path' => $node->path,
                'ordinal' => $node->ordinal,
                'position' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $existing = $this->existingIds($version->id);
        $rows = [];

        foreach ($parsed->topLevelSections as $section) {
            $this->collectRows($version->id, null, $section, $rows, $existing, $section->outlineIndex === null ? null : $outlineIds[$section->outlineIndex]);
        }

        DB::transaction(function () use ($version, $rows, $outlineRows): void {
            $oldOutline = SourceOutlineNode::query()->where('document_version_id', $version->id)->pluck('id')->all();

            foreach (array_chunk($outlineRows, self::INSERT_BATCH) as $batch) {
                SourceOutlineNode::query()->insert($batch);
            }

            // In batches, parents before children: a consolidation of the Codul fiscal is some
            // 7 000 sections, and a law is ninety consolidations — one statement each would be the
            // slowest part of reading a law by far. A row whose id is kept is updated in place.
            foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
                SourceSection::query()->upsert($batch, ['id'], array_values(array_diff(array_keys($batch[0]), ['id', 'created_at'])));
            }

            // Last, so every kept row already points at its new parent and heading.
            SourceSection::query()
                ->where('document_version_id', $version->id)
                ->whereNotIn('id', array_column($rows, 'id'))
                ->delete();
            SourceOutlineNode::query()->whereIn('id', $oldOutline)->delete();
        });

        $sectionCount = count($rows);

        $status = $effectiveFrom !== null ? SourceDocumentVersionStatus::PARSED : SourceDocumentVersionStatus::REQUIRES_REVIEW;

        $version->forceFill([
            'parser_version' => $parserVersion,
            'status' => $status->value,
            'effective_from' => $effectiveFrom?->format('Y-m-d'),
            'effective_until' => $effectiveUntil?->format('Y-m-d'),
        ])->save();

        $this->backfillPublicationDate($version, $parsed);

        return new LegislationParsingResult(
            sourceDocumentVersionId: $version->id,
            sectionCount: $sectionCount,
            status: $status,
            publicationDate: $parsed->publicationDate?->format('Y-m-d'),
        );
    }

    /**
     * The ids this version's provisions already have, by citation — one each: a citation is unique in
     * a version (`ext_source_sections_citation_unique`).
     *
     * @return array<string, string>
     */
    private function existingIds(string $documentVersionId): array
    {
        /** @var array<string, string> */
        return SourceSection::query()->where('document_version_id', $documentVersionId)->pluck('id', 'citation_label')->all();
    }

    /**
     * One row per section, in reading order — a parent always before its children.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $existing  ids to keep, by citation
     * @param  string|null  $outlineId  the heading an article or annex stands under
     */
    private function collectRows(string $documentVersionId, ?string $parentId, ParsedSection $section, array &$rows, array $existing, ?string $outlineId = null): void
    {
        $id = $existing[$section->citationLabel] ?? (string) Str::uuid7();
        $now = now('UTC');

        $rows[] = [
            'id' => $id,
            'document_version_id' => $documentVersionId,
            'parent_id' => $parentId,
            'section_type' => $section->sectionType->value,
            'citation_label' => $section->citationLabel,
            'ordinal' => $section->ordinal,
            'text' => $section->text,
            'text_hash' => $section->textHash(),
            'tables' => $section->tables === [] ? null : json_encode($section->tables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'amended_on' => SectionText::amendedOn($section->text),
            'normative_hash' => SectionText::fingerprint($section->fullText()),
            'outline_id' => $outlineId,
            'notes' => $section->notes === [] ? null : json_encode(array_map($this->notes->read(...), $section->notes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'page_start' => null,
            'page_end' => null,
            'effective_from' => null,
            'effective_until' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($section->children as $child) {
            $this->collectRows($documentVersionId, $id, $child, $rows, $existing);
        }
    }

    /**
     * `ext_source_documents.publication_date` is only ever set once, from the first version that
     * genuinely carries one — never overwritten with a different value by a later re-parse (a
     * changed publication date would be a data anomaly worth a human's attention, not a silent
     * correction, and no such workflow exists yet).
     */
    private function backfillPublicationDate(SourceDocumentVersion $version, ParsedLegislationDocument $parsed): void
    {
        if ($parsed->publicationDate === null) {
            return;
        }

        /** @var SourceDocument $document */
        $document = SourceDocument::query()->findOrFail($version->source_document_id);

        if ($document->publication_date !== null) {
            return;
        }

        $document->forceFill([
            'publication_date' => CarbonImmutable::instance($parsed->publicationDate)->format('Y-m-d'),
        ])->save();
    }
}
