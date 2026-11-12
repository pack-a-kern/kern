<?php

declare(strict_types=1);

namespace Tests\Feature\Legislation\Retrieval\EmbedLegislationChunksTest;

use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Queue;
use Kern\Legislation\Jobs\EmbedLegislationChunks;

it('queues the embedding once however many laws finish while it waits', function (): void {
    Queue::fake();

    EmbedLegislationChunks::dispatch();
    EmbedLegislationChunks::dispatch();
    EmbedLegislationChunks::dispatch();

    Queue::assertPushed(EmbedLegislationChunks::class, 1);
});

it('may be queued again once the one waiting has started, and a lock left behind goes in time', function (): void {
    Queue::fake();
    $job = new EmbedLegislationChunks();

    EmbedLegislationChunks::dispatch();
    // What the worker does as it takes the job up: the lock is let go.
    (new UniqueLock(cache()->store()))->release($job);
    EmbedLegislationChunks::dispatch();

    Queue::assertPushed(EmbedLegislationChunks::class, 2);
    expect($job->uniqueFor)->toBe($job->timeout);
});
