<?php

declare(strict_types=1);

namespace Kern\Legislation\Requirements;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Kern\Legislation\Models\LawWatch;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Models\SourceDocumentVersion;

/**
 * The requirement needs this package decides — its own domain (ADR 0027). The Romanian tax package
 * registers `parameters` against its own models; this one never learns what a fiscal value is.
 *
 * Each check reuses the lookup that already exists, so readiness cannot drift from what the readers
 * actually require: a law is found by the identifier the reader looks it up by
 * ({@see \Kern\TaxRomania\FiscalParameters\Queries\FiscalParameterBoard}), a watch by the key it was
 * declared under.
 */
final readonly class LegislationNeeds
{
    /**
     * A law is present when a document filed under this identifier has a version in force now — read
     * and dated. A just-ingested version a human has not yet dated has no `effective_from`, so it is
     * not in force and the law is not yet present: exactly the gap that leaves a reader nothing to
     * parse (ADR 0027 context).
     */
    public function lawInForce(string $externalIdentifier): bool
    {
        $lawId = SourceDocument::query()->where('external_identifier', $externalIdentifier)->value('id');

        if (! is_string($lawId)) {
            return false;
        }

        $today = CarbonImmutable::now()->toDateString();

        return SourceDocumentVersion::query()
            ->where('source_document_id', $lawId)
            ->whereNotNull('effective_from')
            ->whereDate('effective_from', '<=', $today)
            ->where(static fn (Builder $version): Builder => $version
                ->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $today))
            ->exists();
    }

    /** A watch's acts are in the library once it has read at least one law under the key it was declared with. */
    public function watchHasRead(string $key): bool
    {
        $watchId = LawWatch::query()->where('key', $key)->value('id');

        return is_string($watchId) && SourceDocument::query()->where('found_by_watch_id', $watchId)->exists();
    }
}
