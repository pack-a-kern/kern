<?php

declare(strict_types=1);

namespace Kern\Legislation\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Kern\Legislation\DataTransferObjects\EffectiveDocumentVersion;
use Kern\Legislation\DataTransferObjects\EffectiveSection;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Exceptions\AmbiguousEffectiveVersionException;
use Kern\Legislation\Exceptions\NoEffectiveVersionFoundException;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;

/**
 * Answers `BLUEPRINT.md` §39's central question — "what text was in force on date X" — without ever
 * guessing (`docs/AGENTS.md` §2). This is the module's single entry point for temporal legal-text
 * lookups; nothing outside this class should compare `effective_from`/`effective_until` columns
 * directly.
 *
 * **Exactly one matching version, or an exception — never a heuristic.** Zero matches
 * ({@see NoEffectiveVersionFoundException}) means the data has a gap (an ingested-but-not-yet-dated
 * version, or a genuine coverage hole) — the caller must not fall back to "the newest version
 * anyway." More than one match ({@see AmbiguousEffectiveVersionException}) means two versions'
 * effective ranges overlap, which is a data-integrity defect to fix, never something this class
 * resolves by picking one.
 */
final readonly class LegislationTemporalQuery
{
    public function versionInForceOn(string $sourceDocumentId, DateTimeImmutable $date): EffectiveDocumentVersion
    {
        $dateString = $date->format('Y-m-d');

        /** @var Collection<int, SourceDocumentVersion> $candidates */
        $candidates = SourceDocumentVersion::query()
            ->where('source_document_id', $sourceDocumentId)
            ->whereNotNull('effective_from')
            ->where('effective_from', '<=', $dateString)
            ->where(function ($query) use ($dateString): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $dateString);
            })
            ->get();

        if ($candidates->count() === 0) {
            throw NoEffectiveVersionFoundException::forDate($sourceDocumentId, $date);
        }

        if ($candidates->count() > 1) {
            /** @var list<string> $candidateIds */
            $candidateIds = $candidates->map(static fn (SourceDocumentVersion $version): string => $version->id)->all();

            throw AmbiguousEffectiveVersionException::forDate($sourceDocumentId, $date, $candidateIds);
        }

        /** @var SourceDocumentVersion $version */
        $version = $candidates->first();
        $effectiveFrom = $version->effective_from;

        if ($effectiveFrom === null) {
            // Cannot happen: the query above filters whereNotNull('effective_from'). Narrows the
            // type for PHPStan without a fresh, unnecessary database round trip.
            throw NoEffectiveVersionFoundException::forDate($sourceDocumentId, $date);
        }

        return new EffectiveDocumentVersion(
            id: $version->id,
            effectiveFrom: $effectiveFrom->toDateTimeImmutable(),
            effectiveUntil: $version->effective_until?->toDateTimeImmutable(),
            contentSha256: $version->content_sha256,
        );
    }

    /**
     * @return list<EffectiveSection>
     */
    public function sectionsInForceOn(string $sourceDocumentId, DateTimeImmutable $date, ?SectionType $type = null): array
    {
        $version = $this->versionInForceOn($sourceDocumentId, $date);

        $allSections = SourceSection::query()
            ->where('document_version_id', $version->id)
            ->orderBy('ordinal')
            ->get();

        $byParent = [];
        foreach ($allSections as $section) {
            $byParent[$section->parent_id ?? ''][] = $section;
        }

        $ordered = [];
        $this->flattenDepthFirst(null, $byParent, $ordered);

        $result = [];
        foreach ($ordered as $section) {
            if (! $this->sectionCoversDate($section, $date)) {
                continue;
            }

            if ($type !== null && $section->section_type !== $type) {
                continue;
            }

            $result[] = new EffectiveSection(
                id: $section->id,
                parentId: $section->parent_id,
                sectionType: $section->section_type,
                citationLabel: $section->citation_label,
                text: $section->text,
                textHash: $section->text_hash,
            );
        }

        return $result;
    }

    /**
     * @param array<string, list<SourceSection>> $byParent
     * @param list<SourceSection> $out
     */
    private function flattenDepthFirst(?string $parentId, array $byParent, array &$out): void
    {
        foreach ($byParent[$parentId ?? ''] ?? [] as $section) {
            $out[] = $section;
            $this->flattenDepthFirst($section->id, $byParent, $out);
        }
    }

    /**
     * A section with no dates of its own inherits the parent version's range (already confirmed
     * to cover `$date` by {@see versionInForceOn()}). A section that *does* carry its own dates
     * can be narrower than its version — never wider, since a version's range was already checked.
     */
    private function sectionCoversDate(SourceSection $section, DateTimeImmutable $date): bool
    {
        $carbonDate = CarbonImmutable::instance($date);

        if ($section->effective_from !== null && $carbonDate->lt($section->effective_from)) {
            return false;
        }

        if ($section->effective_until !== null && $carbonDate->gt($section->effective_until)) {
            return false;
        }

        return true;
    }
}
