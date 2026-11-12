<?php

declare(strict_types=1);

namespace Kern\Legislation\Provisions;

use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;

/**
 * A law's provisions as stored, for another package to read its words: the reading of the bonds a
 * Ministry of Finance order issues. From the database, never from the page the law was read from —
 * a page the object store no longer holds leaves the law's words as readable as before.
 *
 * Read-only, and the only way in: a package reading a law's words knows nothing of how they are stored.
 */
final readonly class StoredProvisions
{
    /**
     * The latest version read of a law: its first provisions, each with what stands under it.
     *
     * @return list<StoredProvision>
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException a law with no version read
     */
    public function latest(string $documentId): array
    {
        $version = SourceDocumentVersion::query()->where('source_document_id', $documentId)->latest('retrieved_at')->firstOrFail();
        $byParent = [];

        foreach (SourceSection::query()->where('document_version_id', $version->id)->orderBy('ordinal')->get(['id', 'parent_id', 'section_type', 'citation_label', 'text', 'tables']) as $section) {
            $byParent[$section->parent_id ?? ''][] = $section;
        }

        return self::under('', $byParent);
    }

    /**
     * @param  array<string, list<SourceSection>>  $byParent  each provision's own, by its id — the first ones under ''
     * @return list<StoredProvision>
     */
    private static function under(string $parent, array $byParent): array
    {
        return array_map(
            static fn (SourceSection $section): StoredProvision => new StoredProvision(
                $section->section_type,
                $section->citation_label,
                $section->text,
                $section->tables ?? [],
                self::under($section->id, $byParent),
            ),
            $byParent[$parent] ?? [],
        );
    }
}
