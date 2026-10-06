<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;
use Kern\TaxRomania\Spv\Models\SpvSyncRun;

/**
 * `GET .../spv/connections` — this taxpayer's SPV connections and what each last did.
 *
 * The username is decrypted and returned: it is the taxpayer's own login name, which they typed and
 * will be shown again in the form that asks for the password. It is encrypted at rest because it
 * identifies a person to ANAF, not because it is a secret from the person it belongs to.
 *
 * No password is returned, because none is stored — {@see \Kern\TaxRomania\Spv\Enums\SpvCredentialPolicy}
 * holds exactly one case and that is the whole reason it exists as a column.
 *
 * `run_count` is here rather than counted on the client because it decides which endings the screen
 * offers — see {@see \Kern\TaxRomania\Spv\Actions\DeleteSpvConnection}.
 */
final class ShowSpvConnectionsController
{
    public function __invoke(string $subject): JsonResponse
    {
        /** @var list<SpvConnection> $connections */
        $connections = SpvConnection::query()->orderBy('created_at')->get()->all();

        // The newest run per connection, for "last checked" beside each. One query for the page
        // rather than one per row: a taxpayer has a handful of connections and this is a listing.
        /*
         * The signed-in browser per connection, if there is one.
         *
         * `expires_at` filters here rather than on the client: the worker drops a session at its own TTL
         * and tells nobody, so a row still saying AUTHENTICATED can describe a browser that is gone.
         * A screen comparing timestamps itself would be right only until the tab was left open.
         */
        $liveSessions = [];

        /** @var SpvSession $session */
        foreach (SpvSession::query()
            ->where('state', SpvSessionState::AUTHENTICATED)
            ->whereNull('destroyed_at')
            ->where('expires_at', '>', now('UTC'))
            ->orderByDesc('created_at')
            ->get() as $session) {
            $liveSessions[$session->connection_id] ??= $session;
        }

        $latest = [];
        $runCounts = [];

        /** @var SpvSyncRun $run */
        foreach (SpvSyncRun::query()->orderByDesc('started_at')->get() as $run) {
            $latest[$run->connection_id] ??= $run;
            $runCounts[$run->connection_id] = ($runCounts[$run->connection_id] ?? 0) + 1;
        }

        $rows = [];

        foreach ($connections as $connection) {
            $run = $latest[$connection->id] ?? null;
            $summary = $run?->summary_json;

            $rows[] = [
                'id' => $connection->id,
                'auth_method' => $connection->auth_method->value,
                'credential_policy' => $connection->credential_policy->value,
                'username' => $connection->username_ciphertext,
                'status' => $connection->status->value,
                'last_success_at' => $connection->last_success_at?->toAtomString(),
                'last_error_code' => $connection->last_error_code,
                // The portal's own words, where it gave any. The code above says a sign-in failed; this
                // says which credential, or that the account is locked rather than mistyped.
                'last_error_message' => $connection->last_error_message,
                // What deleting this would destroy, and therefore which endings the screen offers:
                // revoking preserves a history, and a connection that never ran has none.
                'run_count' => $runCounts[$connection->id] ?? 0,
                /*
                 * Whether a browser is open and signed in, and until when.
                 *
                 * What the screen gates "Check inbox" on: reading the portal happens in the browser the
                 * sign-in left open, so without one there is nothing to read with and the action should
                 * not be offered. A certificate connection has no session and needs none — it
                 * authenticates each request on its own — so this is null there and the screen offers the
                 * action anyway.
                 */
                'session' => array_key_exists($connection->id, $liveSessions)
                    ? ['expires_at' => $liveSessions[$connection->id]->expires_at->toAtomString()]
                    : null,
                'last_run' => $run === null ? null : [
                    'status' => $run->status->value,
                    'started_at' => $run->started_at->toAtomString(),
                    'completed_at' => $run->completed_at?->toAtomString(),
                    'message_count' => is_array($summary) && is_int($summary['message_count'] ?? null)
                        ? $summary['message_count']
                        : null,
                ],
            ];
        }

        return response()->json(['connections' => $rows]);
    }
}
