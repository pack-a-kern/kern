<?php

declare(strict_types=1);

namespace Kern\Legislation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\Legislation\Retrieval\Embedding\Exceptions\EmbeddingProviderNotConfiguredException;
use Kern\Legislation\Retrieval\Indexing\EmbedIndexedChunks;

/**
 * Gives the chunks still without a vector one, a batch at a time, until none is left.
 *
 * Queued behind the reading, not inside it: a law's first consolidation is some 7 000 chunks for a
 * model running on this machine's CPU, and every later one mostly copies vectors already computed.
 * Without an embedding model the chunks stay searchable by their words, and this simply stops.
 *
 * One waiting at a time: it embeds every law's chunks, not one law's, so the law that finishes while one
 * waits has nothing to add to the queue. Its lock goes when it starts, so it can queue itself for the next
 * batch, and a law finished meanwhile queues the one that picks its chunks up. A lock left by a job that
 * never ran goes after the time one may take.
 */
final class EmbedLegislationChunks implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public function __construct()
    {
        $this->timeout = config()->integer('legislation.job_timeout_seconds');
        $this->uniqueFor = $this->timeout;
        $this->onQueue(config()->string('legislation.queue'));
    }

    public function handle(EmbedIndexedChunks $embed): void
    {
        try {
            $done = $embed(null, config()->integer('legislation.embedding.batch'));
        } catch (EmbeddingProviderNotConfiguredException) {
            return;
        }

        if ($done > 0) {
            self::dispatch();
        }
    }
}
