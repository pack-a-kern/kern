<?php

declare(strict_types=1);

namespace Kern\Legislation\Jobs;

use Illuminate\Bus\Queueable;
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
 */
final class EmbedLegislationChunks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct()
    {
        $this->timeout = config()->integer('legislation.job_timeout_seconds');
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
