<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Actions\DeleteLaw;
use Kern\Legislation\Jobs\DeleteLawJob;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Repositories\OfficialSourceRepository;

/**
 * `DELETE /api/v1/legislation/laws/{law}` — a law read in from the portal, removed with everything
 * read from it.
 *
 * `ignore` passes the act over in every watch's searches from then on, when a watch read it in.
 *
 * Only a legislation document: the same table holds the exchange-rate history and the bank lists,
 * which have no provisions to protect them and are what every past-year conversion rests on. Those
 * are not laws and are not found here.
 *
 * What would refuse the deletion is checked here, while the asker waits, so the answer is immediate: a
 * fiscal value resting on a paragraph (`LAW_HAS_VALUES`), or a record elsewhere read from the law
 * (`LAW_IS_READ_FROM`). The deleting itself is minutes of work on a law of hundreds of thousands of
 * provisions — too long to hold the request open — so a law clear to go is removed by {@see DeleteLawJob}
 * and the answer is `202`: the screen hears over the socket ({@see \Kern\Legislation\Events\LawDeletionChanged})
 * when it is done or could not be.
 */
final class DeleteLawController
{
    public function __invoke(Request $request, string $law, DeleteLaw $delete, OfficialSourceRepository $sources): JsonResponse
    {
        $found = SourceDocument::query()
            ->where('official_source_id', $sources->findIdForCode('LEGISLATIE'))
            ->find($law);

        abort_if(! $found instanceof SourceDocument, 404);

        $resting = $delete->resting($found);

        if ($resting > 0) {
            return response()->json([
                // `reason` is what a screen translates; the message is for logs and other clients.
                'reason' => 'LAW_HAS_VALUES',
                'message' => 'Fiscal values were read from this law and rest on its paragraphs.',
                'values' => $resting,
            ], 409);
        }

        $readFrom = $delete->readFrom($found);

        if ($readFrom !== []) {
            return response()->json([
                'reason' => 'LAW_IS_READ_FROM',
                'message' => 'Records elsewhere in the application were read from this law and rest on it.',
                'read_from' => $readFrom,
            ], 409);
        }

        $ignore = $request->validate(['ignore' => ['sometimes', 'boolean']])['ignore'] ?? false;
        $actor = $request->user()?->getAuthIdentifier();

        DeleteLawJob::dispatch(
            $found->id,
            is_scalar($actor) ? (string) $actor : null,
            (bool) $ignore,
            is_scalar($actor) ? (string) $actor : null,
        );

        return response()->json(['id' => $found->id, 'status' => 'deleting'], 202);
    }
}
