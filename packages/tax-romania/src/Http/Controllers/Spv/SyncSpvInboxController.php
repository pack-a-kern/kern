<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\Spv\Actions\SyncSpvInbox;

/**
 * `POST .../spv/connections/{connection}/sync` — fetch what is in the SPV inbox.
 *
 * One endpoint for both authentication methods, because which transport runs is the connection's
 * property and not the caller's choice: a certificate connection reaches ANAF's documented API, a
 * portal connection drives the browser session the three sign-in steps just authenticated.
 *
 * Synchronous, and not only for the reason credentials are: the browser session this may use lives
 * for minutes and belongs to the person waiting in front of it.
 */
final class SyncSpvInboxController
{
    public function __invoke(Request $request, string $subject, string $connection, SyncSpvInbox $sync): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $run = $sync($connection, $user->id);
        $summary = $run->summary_json;

        return response()->json([
            'status' => $run->status->value,
            'started_at' => $run->started_at->toAtomString(),
            'completed_at' => $run->completed_at?->toAtomString(),
            'message_count' => is_array($summary) && is_int($summary['message_count'] ?? null)
                ? $summary['message_count']
                : null,
            // The closed failure code, never the transport's own message: that can embed a URI, and
            // a person reading "could not reach ANAF" is better served than by a stack of hosts.
            'failure' => is_array($summary) && is_string($summary['failure'] ?? null) ? $summary['failure'] : null,
        ]);
    }
}
