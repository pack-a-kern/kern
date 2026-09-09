<?php

declare(strict_types=1);

namespace App\Domain\Spv\DataTransferObjects;

use DateTimeImmutable;

/**
 * One ANAF SPV inbox entry, as returned by either `App\Domain\Spv\Contracts\
 * SpvInboxSyncTransport` implementation — a transport-agnostic shape so `App\Domain\Spv\Actions\
 * SyncSpvInbox` never branches on which transport produced it.
 */
final readonly class SpvInboxMessage
{
    /**
     * @param array<string, scalar|null> $metadata transport-specific extra fields (e.g. the
     *                                              certificate API's `cif`/`tip`/`id_solicitare`),
     *                                              stored verbatim in
     *                                              `spv_messages.metadata_ciphertext`
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public DateTimeImmutable $receivedAt,
        public array $metadata = [],
    ) {
    }
}
