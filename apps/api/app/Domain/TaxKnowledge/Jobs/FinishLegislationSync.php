<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Jobs;

use App\Domain\TaxKnowledge\Enums\SourceDocumentVersionStatus;
use App\Domain\TaxKnowledge\FiscalParameters\Jobs\ReadMinimumWages;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Parsing\SectionText;
use App\Domain\TaxKnowledge\Services\BuildSectionHistory;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Every consolidation of a law is read: date each against the next, rebuild the history, embed.
 *
 * A consolidation is the law from its date until the day before the next one — the portal says when
 * each begins, and the next beginning is the end. The newest has no end. `effective_until` is
 * inclusive, as everywhere a date range is read in this module, so no day falls in two versions.
 *
 * A version of a law read from the portal that is not a consolidation is an interrupted read: a
 * job killed after downloading a page and before marking it read. Its retry downloads the page
 * again — the portal never serves the same bytes twice — so the first copy would stay, undated, and
 * search would offer its text as "date unknown". Its sections go here; the downloaded bytes stay in
 * the immutable store, like every file ever fetched.
 */
final class FinishLegislationSync implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $sourceDocumentId)
    {
        $this->timeout = config()->integer('horizon.defaults.supervisor-legislation.timeout');
        $this->onQueue(config()->string('legislation.queue'));
    }

    public function handle(BuildSectionHistory $history): void
    {
        $document = SourceDocument::query()->find($this->sourceDocumentId);

        if ($document === null) {
            return;
        }

        $interrupted = SourceDocumentVersion::query()
            ->where('source_document_id', $document->id)
            ->whereNull('consolidation_ref')
            ->pluck('id')
            ->all();

        if ($interrupted !== []) {
            DB::transaction(static function () use ($interrupted): void {
                SourceDocumentVersion::query()->whereIn('supersedes_version_id', $interrupted)->update(['supersedes_version_id' => null]);
                SourceSection::query()->whereIn('document_version_id', $interrupted)->delete();
                SourceDocumentVersion::query()->whereIn('id', $interrupted)->delete();
            });
        }

        $versions = SourceDocumentVersion::query()
            ->where('source_document_id', $document->id)
            ->whereNotNull('consolidation_ref')
            ->whereNotNull('effective_from')
            ->orderBy('effective_from')
            ->get()
            ->values();

        foreach ($versions as $index => $version) {
            $next = $versions->get($index + 1);

            $version->forceFill([
                'effective_until' => $next?->effective_from === null ? null : CarbonImmutable::parse($next->effective_from)->subDay()->format('Y-m-d'),
                'supersedes_version_id' => $versions->get($index - 1)?->id,
                'status' => SourceDocumentVersionStatus::PARSED->value,
            ])->save();
        }

        $history($document->id);

        $document->forceFill(['last_synced_at' => now('UTC')])->save();

        EmbedLegislationChunks::dispatch();

        // A decision setting the minimum wage: its value proposed as soon as it is read.
        if ($document->document_type === config()->string('fiscal_parameters.minimum_wage.document_type')
            && str_contains(SectionText::searchable($document->title ?? ''), config()->string('fiscal_parameters.minimum_wage.title'))) {
            ReadMinimumWages::dispatch();
        }
    }
}
