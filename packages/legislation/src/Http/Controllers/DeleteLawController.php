<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Legislation\Actions\DeleteLaw;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Repositories\OfficialSourceRepository;

/**
 * `DELETE /api/v1/legislation/laws/{law}` — a law read in from the portal, removed with everything
 * read from it.
 *
 * Only a legislation document: the same table holds the exchange-rate history and the bank lists,
 * which have no provisions to protect them and are what every past-year conversion rests on. Those
 * are not laws and are not found here.
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

        $actor = $request->user()?->getAuthIdentifier();

        return response()->json($delete($found, is_scalar($actor) ? (string) $actor : null), 200);
    }
}
