<?php

declare(strict_types=1);

namespace Kern\Audit\Http\Controllers\Audit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Audit\Queries\AuditEventQuery;
use Kern\Audit\Queries\AuditReach;
use Kern\Audit\Services\ChainVerifier;

/**
 * `GET /api/v1/audit/summary` — counts, the distinct event types (so the filter offers only what
 * actually occurs), and the result of really re-verifying the hash chains.
 *
 * The integrity figure is computed by walking the chains and recomputing every hash, not read from
 * a stored flag: a tamper-evidence indicator that trusts a column is evidence of nothing. Chains
 * are per-subject (§4.8), so this verifies each and reports the first that fails — each the reader
 * can see ({@see AuditReach}): the counts, the event types and the chains checked are all theirs.
 */
final class ShowAuditSummaryController
{
    /** Verifying every chain on every page load would not scale; this bounds the work per request. */
    private const int MAX_CHAINS_VERIFIED = 25;

    public function __invoke(Request $request, AuditEventQuery $query, ChainVerifier $verifier): JsonResponse
    {
        $reach = AuditReach::for($request->user());
        $chainKeys = $query->chainKeys($reach, self::MAX_CHAINS_VERIFIED);

        $verified = 0;
        $broken = null;

        foreach ($chainKeys as $chainKey) {
            $result = $verifier->verify($chainKey);
            $verified += $result->verifiedCount;

            if (! $result->ok) {
                $broken = [
                    'chain_key' => $result->chainKey,
                    'sequence' => $result->brokenAtSequence,
                    'reason' => $result->reason?->value,
                ];

                break;
            }
        }

        return response()->json([
            ...$query->summary($reach),
            'reach' => $reach->value,
            'integrity' => [
                'ok' => $broken === null,
                'chains_checked' => count($chainKeys),
                'events_verified' => $verified,
                'broken' => $broken,
            ],
        ]);
    }
}
