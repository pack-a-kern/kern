<?php

declare(strict_types=1);

namespace App\Domain\Operations\DataTransferObjects;

/**
 * One row of `failed_jobs`, reduced to what an operations screen may show.
 *
 * The stored payload is deliberately not exposed. A failed job's serialized payload carries the
 * job object's constructor arguments — for this application that includes document ids, tax
 * subject ids and, through `SerializesModels`, whatever a re-hydrated model would hold. Publishing
 * it to a browser would make this screen a bypass around every access control the rest of the
 * application enforces (`BLUEPRINT.md` §4.10). The job's class name is enough to know what broke.
 *
 * `exception` is trimmed to its first line for the same reason a stack trace is not rendered: the
 * frames name file paths inside the container, and the message alone is what identifies the fault.
 * The full trace stays in the row and in the logs, where it is already reachable by an operator
 * with shell access.
 */
final readonly class FailedJobSummary
{
    public function __construct(
        public string $uuid,
        public string $connection,
        public string $queue,
        public string $jobName,
        public string $exception,
        public string $failedAt,
    ) {
    }

    /**
     * @return array{
     *     uuid: string, connection: string, queue: string, job_name: string,
     *     exception: string, failed_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'job_name' => $this->jobName,
            'exception' => $this->exception,
            'failed_at' => $this->failedAt,
        ];
    }
}
