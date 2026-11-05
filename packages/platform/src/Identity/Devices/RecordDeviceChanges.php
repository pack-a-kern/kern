<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Devices;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Notifications\Events\DeviceAdded;
use Kern\Notifications\Events\DeviceRemoved;

/**
 * A device turned on or off for a user's notices, in the audit trail: one more place their notices
 * reach, or one fewer, is a change to the account like any other.
 */
final readonly class RecordDeviceChanges
{
    public function __construct(private AuditChainService $audit)
    {
    }

    public function handle(DeviceAdded|DeviceRemoved $event): void
    {
        $actor = $event->notifiable->getKey();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: is_string($actor) ? $actor : null,
            eventType: $event instanceof DeviceAdded ? 'PushDeviceAdded' : 'PushDeviceRemoved',
            resourceType: 'PushSubscription',
            resourceId: $event->deviceId,
            payloadHash: $this->audit->hashPayload([]),
        ));
    }
}
