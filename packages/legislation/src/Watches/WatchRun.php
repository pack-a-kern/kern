<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use Kern\Legislation\Enums\PortalSearchFailure;

/**
 * What one run of a watch did, kept as counts a screen words in its own language: the acts its search
 * found, how many it read in as new laws, how many were laws already or ignored, how many it left for the
 * next run or could not start, how often the portal was asked again — and, when the search itself
 * stopped, why.
 */
final readonly class WatchRun
{
    /** @param  list<int>  $years  the years searched */
    public function __construct(
        public array $years,
        public int $found,
        public int $started,
        public int $known,
        public int $ignored,
        public int $waiting,
        public int $failed,
        public int $retries,
        public ?PortalSearchFailure $failure,
    ) {
    }

    /** @param  list<int>  $years */
    public static function stopped(array $years, PortalSearchFailure $failure): self
    {
        return new self($years, 0, 0, 0, 0, 0, 0, 0, $failure);
    }

    /** Whether it did everything it found to do: the clean run a watch's next one counts from. */
    public function finished(): bool
    {
        return $this->failure === null && $this->waiting === 0 && $this->failed === 0;
    }

    /** @return array{years: list<int>, found: int, started: int, known: int, ignored: int, waiting: int, failed: int, retries: int, failure: string|null} */
    public function toArray(): array
    {
        return [
            'years' => $this->years,
            'found' => $this->found,
            'started' => $this->started,
            'known' => $this->known,
            'ignored' => $this->ignored,
            'waiting' => $this->waiting,
            'failed' => $this->failed,
            'retries' => $this->retries,
            'failure' => $this->failure?->value,
        ];
    }

    /** One line for the scheduled task's output. */
    public function line(): string
    {
        if ($this->failure !== null) {
            return "FAILED: {$this->failure->value}";
        }

        return sprintf('%d acts found in %s, %d new read as laws', $this->found, implode(', ', $this->years), $this->started)
            . ($this->known === 0 ? '' : ", {$this->known} already laws")
            . ($this->ignored === 0 ? '' : ", {$this->ignored} ignored")
            . ($this->waiting === 0 ? '' : "; {$this->waiting} left for the next run")
            . ($this->failed === 0 ? '' : "; FAILED to start {$this->failed}");
    }
}
