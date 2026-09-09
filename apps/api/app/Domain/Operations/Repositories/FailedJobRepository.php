<?php

declare(strict_types=1);

namespace App\Domain\Operations\Repositories;

use App\Domain\Operations\DataTransferObjects\FailedJobSummary;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Reads and clears `failed_jobs` through Laravel's own failed-job provider.
 *
 * Through the provider rather than the table directly so this keeps working if the failed-job
 * driver is ever changed in `config/queue.php` — the provider is the contract, the table is one
 * implementation of it.
 *
 * What is *not* exposed is the point of {@see FailedJobSummary}: the serialized payload carries the
 * job's constructor arguments, which in this application include document and tax-subject ids and,
 * via `SerializesModels`, whatever re-hydrating those models would surface. Rendering it in a
 * browser would turn this screen into a bypass around every access control the rest of the
 * application enforces (`BLUEPRINT.md` §4.10).
 */
final readonly class FailedJobRepository
{
    public function __construct(
        private FailedJobProviderInterface $provider,
        private ConfigRepository $config,
        private Kernel $artisan,
    ) {
    }

    /**
     * Newest first, capped. This screen is for triaging the failures of the last few hours; a
     * queue with thousands of failed jobs is a different problem, and paging through them in a
     * browser is not how it gets solved.
     *
     * @return list<FailedJobSummary>
     */
    public function recent(): array
    {
        $limit = $this->config->get('operations.freshness.failed_jobs_limit');
        $limit = is_int($limit) && $limit > 0 ? $limit : 50;

        $rows = array_slice($this->provider->all(), 0, $limit);

        return array_values(array_map(fn (mixed $row): FailedJobSummary => $this->summarise($row), $rows));
    }

    /**
     * Re-queue one failed job. Delegated to `queue:retry` rather than reimplemented: that command
     * owns the payload-rewriting rules (a new uuid, a reset attempt count) and getting them subtly
     * wrong produces a job that runs but can never be retried again.
     */
    public function retry(string $uuid): void
    {
        $this->artisan->call('queue:retry', ['id' => [$uuid]]);
    }

    public function forget(string $uuid): void
    {
        $this->provider->forget($uuid);
    }

    public function exists(string $uuid): bool
    {
        return $this->provider->find($uuid) !== null;
    }

    private function summarise(mixed $row): FailedJobSummary
    {
        $data = (array) $row;

        return new FailedJobSummary(
            uuid: $this->str($data, 'uuid'),
            connection: $this->str($data, 'connection'),
            queue: $this->str($data, 'queue'),
            jobName: $this->jobName($this->str($data, 'payload')),
            exception: $this->firstLine($this->str($data, 'exception')),
            failedAt: $this->timestamp($data['failed_at'] ?? null),
        );
    }

    /**
     * The display name Laravel already stores in the payload — never the payload itself. Falls back
     * to the raw class if `displayName` is absent, and to a neutral label if the payload cannot be
     * read at all, so an unparseable row still lists rather than disappearing.
     */
    private function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return 'unknown';
        }

        foreach (['displayName', 'job'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * A stack trace names container file paths and, frame by frame, the arguments a failure was
     * carrying. The first line identifies the fault; the rest stays in the row and in the logs,
     * where an operator with shell access already has it.
     */
    private function firstLine(string $exception): string
    {
        $line = strtok($exception, "\n");

        return $line === false ? '' : trim($line);
    }

    private function timestamp(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return CarbonImmutable::parse($value)->toIso8601String();
        }

        return '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
