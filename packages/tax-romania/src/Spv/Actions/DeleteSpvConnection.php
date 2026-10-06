<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSyncRun;

/**
 * Removes a connection and everything recorded against it.
 *
 * ## Why this exists beside `RevokeSpvConnection`
 *
 * Revoking keeps the row, because the sync runs point at it and a history of calls to a connection
 * nothing can name is worse than a tidy list. But a connection that was registered by mistake, or
 * with the wrong username, and never used, has no history to protect — and leaving it in the list
 * marked "revoked" forever is an answer to a question nobody asked.
 *
 * So the screen offers revoking only once there is something to preserve, and deleting always.
 *
 * ## What deleting takes with it
 *
 * `spv_sessions.connection_id` and `spv_sync_runs.connection_id` are both `ON DELETE CASCADE`, so the
 * sync history goes too. That is the cost, it is why the dialog states the count, and it is why
 * revoking stays the default for a connection that has run.
 *
 * **The audit trail does not go.** `audit_events` references a resource by a string id with no
 * foreign key, precisely so that the record of what somebody did outlives the thing they did it to.
 * The event recorded here names the count destroyed, so the trail says what was lost rather than
 * merely that something was.
 */
final readonly class DeleteSpvConnection
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(string $connectionId, ?string $actorId): void
    {
        /** @var SpvConnection $connection */
        $connection = SpvConnection::query()->findOrFail($connectionId);

        $runCount = SpvSyncRun::query()->where('connection_id', $connection->id)->count();

        // Recorded before the delete, while the row is still there to be described. The chain is
        // append-only, so an event about a row that no longer exists is the normal case, not an
        // orphan.
        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $connection->tax_subject_id,
            actorId: $actorId,
            eventType: 'SpvConnectionDeleted',
            resourceType: 'SpvConnection',
            resourceId: $connection->id,
            payloadHash: $this->audit->hashPayload(['sync_runs_destroyed' => $runCount]),
        ));

        $connection->delete();
    }
}
