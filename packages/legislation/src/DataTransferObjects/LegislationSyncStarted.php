<?php

declare(strict_types=1);

namespace Kern\Legislation\DataTransferObjects;

/** What a sync found when it looked, before any consolidation is read. */
final readonly class LegislationSyncStarted
{
    public function __construct(
        public string $sourceDocumentId,
        public string $title,
        /** Consolidations the portal lists within the requested range. */
        public int $listed,
        /** Of those, the ones not stored yet — each read by a job of its own. */
        public int $toRead,
    ) {
    }
}
