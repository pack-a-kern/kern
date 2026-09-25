<?php

declare(strict_types=1);

namespace Kern\Legislation\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Legislation\Dependents\SectionDependents;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;
use Kern\Legislation\Models\SourceSection;

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
 * The provisions, their chunks in the search index (cascade), the outline of each consolidation
 * (cascade), the consolidations, and the histories assembled from them (cascade). Provisions are
 * deleted deepest first: a paragraph's points are its children, and `parent_id` restricts.
 */
final readonly class DeleteLaw
{
    public function __construct(
        private AuditChainService $audit,
        private SectionDependents $dependents,
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

    /** @return array{versions: int, sections: int} what was removed */
    public function __invoke(SourceDocument $law, ?string $actorId = null): array
    {
        // Read before deleting: afterwards the audit entry is the only trace left.
        $identifier = $law->external_identifier;
        $title = $law->title;
        $versions = SourceDocumentVersion::query()->where('source_document_id', $law->id)->pluck('id')->all();
        $sections = SourceSection::query()->whereIn('document_version_id', $versions)->count();

        DB::transaction(function () use ($law, $versions): void {
            // Deepest first: a point belongs to its paragraph, and `parent_id` restricts deletion.
            while (SourceSection::query()->whereIn('document_version_id', $versions)->exists()) {
                $leaves = SourceSection::query()
                    ->whereIn('document_version_id', $versions)
                    ->whereNotExists(function ($query) use ($versions): void {
                        $query->select(DB::raw(1))
                            ->from('ext_source_sections as child')
                            ->whereColumn('child.parent_id', 'ext_source_sections.id')
                            ->whereIn('child.document_version_id', $versions);
                    })
                    ->pluck('id')
                    ->all();

                if ($leaves === []) {
                    break;
                }

                SourceSection::query()->whereIn('id', $leaves)->delete();
            }

            // A consolidation names the one it replaced, so the newest goes first.
            SourceDocumentVersion::query()->whereIn('id', $versions)->update(['supersedes_version_id' => null]);
            SourceDocumentVersion::query()->whereIn('id', $versions)->delete();
            $law->delete();
        });

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
            ]),
        ));

        return ['versions' => count($versions), 'sections' => $sections];
    }
}
