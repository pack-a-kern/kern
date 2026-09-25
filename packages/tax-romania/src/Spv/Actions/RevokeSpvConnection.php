<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\TaxRomania\Spv\Enums\SpvConnectionStatus;
use Kern\TaxRomania\Spv\Models\SpvConnection;

/** The natural counterpart to `RegisterSpvConnection` — a user must be able to disconnect SPV. */
final readonly class RevokeSpvConnection
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function __invoke(string $connectionId, ?string $actorId): SpvConnection
    {
        /** @var SpvConnection $connection */
        $connection = SpvConnection::query()->findOrFail($connectionId);

        $connection->status = SpvConnectionStatus::REVOKED;
        $connection->save();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: $connection->tax_subject_id,
            actorId: $actorId,
            eventType: 'SpvConnectionRevoked',
            resourceType: 'SpvConnection',
            resourceId: $connection->id,
            payloadHash: $this->audit->hashPayload([]),
        ));

        return $connection;
    }
}
