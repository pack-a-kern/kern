<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Services;

use App\Domain\TaxKnowledge\DataTransferObjects\LegislationParsingResult;
use App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus;
use App\Domain\TaxKnowledge\Exceptions\UnknownSourceDocumentVersionException;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Parsing\LegislatieJustRoHtmlParser;
use App\Domain\TaxKnowledge\Parsing\ParsedLegislationDocument;
use App\Domain\TaxKnowledge\Parsing\ParsedSection;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns one {@see ParsedLegislationDocument} into persisted `ext_source_sections` rows for a given
 * `ext_source_document_versions` row, and updates that version's parsing bookkeeping columns.
 *
 * **Re-parsing an already-parsed version is supported and safe**: existing sections for that
 * version are deleted and replaced inside one transaction. This is *not* "overwriting a raw
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
 * otherwise. Every `legislatie.just.ro` version parsed by
 * {@see LegislatieJustRoHtmlParser} today ends up `REQUIRES_REVIEW`, honestly, because that source
 * exposes no machine-readable effective-date field (see that parser's docblock) — a human supplies
 * the dates later via a review step this task does not build.
 */
final readonly class LegislationSectionPersister
{
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

        $sectionCount = DB::transaction(function () use ($version, $parsed): int {
            SourceSection::query()->where('document_version_id', $version->id)->delete();

            $count = 0;
            foreach ($parsed->topLevelSections as $section) {
                $count += $this->persistSection($version->id, null, $section);
            }

            return $count;
        });

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

    private function persistSection(string $documentVersionId, ?string $parentId, ParsedSection $section): int
    {
        $row = SourceSection::query()->create([
            'document_version_id' => $documentVersionId,
            'parent_id' => $parentId,
            'section_type' => $section->sectionType,
            'citation_label' => $section->citationLabel,
            'ordinal' => $section->ordinal,
            'text' => $section->text,
            'text_hash' => $section->textHash(),
            'page_start' => null,
            'page_end' => null,
            'effective_from' => null,
            'effective_until' => null,
        ]);

        $count = 1;
        foreach ($section->children as $child) {
            $count += $this->persistSection($documentVersionId, $row->id, $child);
        }

        return $count;
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
