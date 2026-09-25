<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Kern\Audit\Queries\AuditEventQuery;
use Kern\Audit\Services\ChainVerifier;

/**
 * `GET /api/v1/audit/summary` — counts, the distinct event types (so the filter offers only what
 * actually occurs), and the result of really re-verifying the hash chains.
 *
 * The integrity figure is computed by walking the chains and recomputing every hash, not read from
 * a stored flag: a tamper-evidence indicator that trusts a column is evidence of nothing. Chains
 * are per-subject (§4.8), so this verifies each and reports the first that fails.
 */
final class ShowAuditSummaryController extends Controller
{
    /** Verifying every chain on every page load would not scale; this bounds the work per request. */
    private const int MAX_CHAINS_VERIFIED = 25;

    public function __invoke(AuditEventQuery $query, ChainVerifier $verifier): JsonResponse
    {
        /** @var list<string> $chainKeys */
        $chainKeys = DB::table('audit_events')
            ->distinct()
            ->orderBy('chain_key')
            ->limit(self::MAX_CHAINS_VERIFIED)
            ->pluck('chain_key')
            ->all();

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
            ...$query->summary(),
            'integrity' => [
                'ok' => $broken === null,
                'chains_checked' => count($chainKeys),
                'events_verified' => $verified,
                'broken' => $broken,
            ],
        ]);
    }
}
