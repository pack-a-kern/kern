<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Spv;

use Illuminate\Http\JsonResponse;
use Kern\TaxRomania\Spv\Models\SpvConnection;
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
 */
final class ShowSpvConnectionsController
{
    public function __invoke(string $subject): JsonResponse
    {
        /** @var list<SpvConnection> $connections */
        $connections = SpvConnection::query()->orderBy('created_at')->get()->all();

        // The newest run per connection, for "last checked" beside each. One query for the page
        // rather than one per row: a taxpayer has a handful of connections and this is a listing.
        $latest = [];

        /** @var SpvSyncRun $run */
        foreach (SpvSyncRun::query()->orderByDesc('started_at')->get() as $run) {
            $latest[$run->connection_id] ??= $run;
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
