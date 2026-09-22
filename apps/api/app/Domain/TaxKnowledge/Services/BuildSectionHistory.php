<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Services;

use App\Domain\TaxKnowledge\Models\SectionHistory;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rebuilds a law's history, provision by provision, from its dated consolidations.
 *
 * The consolidations are walked oldest first. A provision whose fingerprint is the same as in the
 * previous consolidation continues its stretch; a changed one closes it the day before and opens
 * the next; one that is no longer there closes. The whole history is rewritten each time — it is
 * derived, and a consolidation read late (one that failed before) changes what comes after it.
 *
 * Where the portal lists no consolidation for years — the Codul fiscal's, none from December 2016
 * to 2021 — a wording is first read long after it began. Its own note says when: "(la 01-01-2018,
 * … a fost modificat de …)". A new wording whose latest note falls between the consolidation before
 * and the one it is first read in starts on that day, the one before it ends the day before, and
 * the stretch says it is dated by the note. A note outside that gap is not a start the
 * consolidations leave open, and changes nothing.
 */
final readonly class BuildSectionHistory
{
    private const int INSERT_BATCH = 1000;

    /** @return int the stretches written */
    public function __invoke(string $sourceDocumentId): int
    {
        $document = SourceDocument::query()->findOrFail($sourceDocumentId);
        $gaps = array_values(array_map(
            static fn (array $entry): string => $entry['date'],
            array_filter($document->consolidations ?? [], static fn (array $entry): bool => ($entry['failed'] ?? false) === true),
        ));

        $versions = SourceDocumentVersion::query()
            ->where('source_document_id', $document->id)
            ->whereNotNull('consolidation_ref')
            ->whereNotNull('effective_from')
            ->orderBy('effective_from')
            ->get(['id', 'effective_from', 'effective_until']);

        /** @var array<string, array<string, mixed>> $open provision => its current stretch */
        $open = [];
        /** @var array<string, int> $counts provision => stretches so far */
        $counts = [];
        $rows = [];

        $previous = null;

        foreach ($versions as $version) {
            $from = CarbonImmutable::parse($version->effective_from);
            $dayBefore = $from->subDay()->format('Y-m-d');
            $seen = [];

            $sections = SourceSection::query()
                ->where('document_version_id', $version->id)
                ->whereNotNull('normative_hash')
                ->toBase()
                ->get(['id', 'citation_label', 'section_type', 'normative_hash', 'amended_on']);

            foreach ($sections as $section) {
                $label = (string) $section->citation_label;
                $seen[$label] = true;
                $current = $open[$label] ?? null;

                if ($current !== null && $current['normative_hash'] === $section->normative_hash) {
                    $open[$label]['last_section_id'] = (string) $section->id;
                    $open[$label]['snapshot_count']++;
                    $open[$label]['amended_on'] = max($current['amended_on'], $section->amended_on);

                    continue;
                }

                $noted = self::notedStart($section->amended_on, $previous, $from);
                $start = $noted ?? $from;

                if ($current !== null) {
                    $rows[] = [...$current, 'valid_until' => $start->subDay()->format('Y-m-d')];
                }

                $counts[$label] = ($counts[$label] ?? 0) + 1;
                $open[$label] = [
                    'citation_label' => $label,
                    'section_type' => (string) $section->section_type,
                    'version_index' => $counts[$label],
                    'valid_from' => $start->format('Y-m-d'),
                    'dated_by_note' => $noted !== null,
                    'normative_hash' => (string) $section->normative_hash,
                    'first_section_id' => (string) $section->id,
                    'last_section_id' => (string) $section->id,
                    'snapshot_count' => 1,
                    'amended_on' => $section->amended_on,
                ];
            }

            // A provision the consolidation no longer has was repealed or renumbered there.
            foreach (array_diff_key($open, $seen) as $label => $current) {
                $rows[] = [...$current, 'valid_until' => $dayBefore];
                unset($open[$label]);
            }

            $previous = $from;
        }

        $lastUntil = $versions->last()?->effective_until?->format('Y-m-d');

        foreach ($open as $current) {
            $rows[] = [...$current, 'valid_until' => $lastUntil];
        }

        $now = now('UTC');
        $rows = array_map(static fn (array $row): array => [
            ...$row,
            'id' => (string) Str::uuid7(),
            'source_document_id' => $document->id,
            'coverage_complete' => self::covered(is_string($row['valid_from']) ? $row['valid_from'] : '', is_string($row['valid_until']) ? $row['valid_until'] : null, $gaps),
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::transaction(static function () use ($document, $rows): void {
            SectionHistory::query()->where('source_document_id', $document->id)->delete();

            foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
                SectionHistory::query()->insert($batch);
            }
        });

        return count($rows);
    }

    /**
     * The day a new wording began, by its own note, when that day falls strictly between the
     * consolidation before and the one it is first read in — the only days the consolidations leave
     * open. Null when it does not, or when the note gives no day.
     */
    private static function notedStart(mixed $amendedOn, ?CarbonImmutable $previous, CarbonImmutable $from): ?CarbonImmutable
    {
        if ($previous === null || ! is_string($amendedOn) || $amendedOn === '') {
            return null;
        }

        $noted = CarbonImmutable::parse($amendedOn);

        return $noted->greaterThan($previous) && $noted->lessThan($from) ? $noted : null;
    }

    /**
     * Whether every consolidation inside a stretch was read.
     *
     * @param  list<string>  $gaps  dates of consolidations that could not be read
     */
    private static function covered(string $from, ?string $until, array $gaps): bool
    {
        foreach ($gaps as $gap) {
            if ($gap >= $from && ($until === null || $gap <= $until)) {
                return false;
            }
        }

        return true;
    }
}
