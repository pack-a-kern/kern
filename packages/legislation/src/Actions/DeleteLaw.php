<?php

declare(strict_types=1);

namespace Kern\Legislation\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Legislation\Dependents\DocumentDependents;
use Kern\Legislation\Dependents\SectionDependents;
use Kern\Legislation\Events\LawDeleting;
use Kern\Legislation\Models\IgnoredAct;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;
use Kern\Legislation\Storage\RawSourceObjectStore;
use Throwable;

/**
 * Removes a law read in from the portal: every consolidation of it, every provision, and what was
 * built from them.
 *
 * ## Why a law can be deleted at all
 *
 * One is read in to be tried: a text added to see what it holds, an act that turns out to be the
 * wrong one, a decision filed twice. Leaving those behind makes the library a place where mistakes
 * accumulate, and a search answer from an act nobody meant to keep is worse than no answer.
 *
 * ## What refuses
 *
 * A fiscal value approved on a paragraph of this law, or a tax rule sourced from one. Those are the
 * provenance chain: a value's whole claim is that it stands in a paragraph of a law in force that
 * year, and a value pointing at a paragraph that no longer exists is not a value. The database says
 * the same in its own way — `fiscal_parameter_versions.source_section_id` restricts deletion — and
 * this is that refusal, counted and explained rather than a foreign-key error.
 *
 * Nothing here frees a law by rejecting its values: a rejected value keeps its paragraph, because
 * "this was read and refused" is itself a claim about a text. A law the values rest on stays.
 *
 * ## What goes with it
 *
 * Whatever another package read from it and nobody holds — a bond's terms read from the order that issued
 * it, with the bond — on `LawDeleting`, in this same transaction. Then the provisions, their chunks in
 * the search index (cascade), the outline of each consolidation (cascade), the consolidations, and the
 * histories assembled from them (cascade). Provisions are deleted deepest first: a paragraph's points are
 * its children, and `parent_id` restricts.
 *
 * Last, once all that is committed, the pages it was read from in the object store — each only when no
 * other version still names it, since a page is stored once for every version that read the same bytes.
 * A page that will not go is reported and left: the law is already gone, and a stray file is not worth
 * undoing that for.
 */
final readonly class DeleteLaw
{
    public function __construct(
        private AuditChainService $audit,
        private SectionDependents $dependents,
        private DocumentDependents $readFrom,
        private RawSourceObjectStore $pages,
    ) {
    }

    /**
     * How many values rest on this law — nothing may delete it while any do.
     *
     * Every status counts, not the approved alone: a rejected value names the paragraph it was
     * refused on, and the foreign key holds it either way.
     */
    public function resting(SourceDocument $law): int
    {
        $sections = SourceSection::query()
            ->whereIn('document_version_id', SourceDocumentVersion::query()->where('source_document_id', $law->id)->select('id'))
            ->select('id')
            ->toBase();

        return $this->dependents->countFor($sections);
    }

    /**
     * What else rests on this law as a whole — by the name its package declared it under — and holds it
     * as `resting()`'s values hold it: a person's holding of a bond the law issued.
     *
     * @return array<string, int> none, empty
     */
    public function readFrom(SourceDocument $law): array
    {
        return $this->readFrom->countFor($law->id);
    }

    /**
     * @param  bool  $ignore  pass the act over in every watch's searches from then on — only for one a watch
     *                        read in, the only kind a watch would read in again
     * @return array{versions: int, sections: int, pages: int, ignored: bool} what was removed, and whether it is ignored
     */
    public function __invoke(SourceDocument $law, ?string $actorId = null, bool $ignore = false): array
    {
        // Read before deleting: afterwards the audit entry is the only trace left.
        $identifier = $law->external_identifier;
        $title = $law->title;
        $versions = SourceDocumentVersion::query()->where('source_document_id', $law->id)->pluck('id')->all();
        $sections = SourceSection::query()->whereIn('document_version_id', $versions)->count();
        /** @var list<string> $paths */
        $paths = SourceDocumentVersion::query()->whereIn('id', $versions)->distinct()->pluck('raw_object_path')->all();
        $ignored = $ignore && $law->found_by_watch_id !== null && $law->portal_document_id !== null;

        DB::transaction(function () use ($law, $versions, $ignored, $actorId): void {
            LawDeleting::dispatch($law->id);

            // Deepest first: a point belongs to its paragraph, and `parent_id` restricts deletion. A
            // law may hold hundreds of thousands of provisions, so the leaves go a bounded batch at a
            // time — never the whole set in one array — until a pass finds none left to take.
            $chunk = max(1, config()->integer('legislation.delete.chunk'));

            do {
                $leaves = SourceSection::query()
                    ->whereIn('document_version_id', $versions)
                    ->whereNotExists(function ($query) use ($versions): void {
                        $query->select(DB::raw(1))
                            ->from('ext_source_sections as child')
                            ->whereColumn('child.parent_id', 'ext_source_sections.id')
                            ->whereIn('child.document_version_id', $versions);
                    })
                    ->limit($chunk)
                    ->pluck('id')
                    ->all();

                if ($leaves !== []) {
                    SourceSection::query()->whereIn('id', $leaves)->delete();
                }
            } while ($leaves !== []);

            // A consolidation names the one it replaced, so the newest goes first.
            SourceDocumentVersion::query()->whereIn('id', $versions)->update(['supersedes_version_id' => null]);
            SourceDocumentVersion::query()->whereIn('id', $versions)->delete();
            $law->delete();

            if ($ignored) {
                IgnoredAct::query()->firstOrCreate(
                    ['portal_document_id' => $law->portal_document_id],
                    ['title' => $law->title ?? (string) $law->external_identifier, 'ignored_by' => $actorId],
                );
            }
        });

        $pages = $this->forgetPages($paths);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $actorId,
            eventType: 'LawDeleted',
            resourceType: 'SourceDocument',
            resourceId: $law->id,
            payloadHash: $this->audit->hashPayload([
                'external_identifier' => $identifier,
                'title' => $title,
                'versions' => count($versions),
                'sections' => $sections,
                'pages' => $pages,
                'ignored' => $ignored,
            ]),
        ));

        return ['versions' => count($versions), 'sections' => $sections, 'pages' => $pages, 'ignored' => $ignored];
    }

    /**
     * @param  list<string>  $paths  the pages the deleted versions were read from
     * @return int how many were removed
     */
    private function forgetPages(array $paths): int
    {
        /** @var list<string> $named */
        $named = SourceDocumentVersion::query()->whereIn('raw_object_path', $paths)->pluck('raw_object_path')->all();
        $removed = 0;

        foreach (array_diff($paths, $named) as $path) {
            try {
                $this->pages->forget($path);
                $removed++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $removed;
    }
}
