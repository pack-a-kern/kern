<?php

declare(strict_types=1);

namespace Kern\Legislation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\Legislation\Actions\DeleteLaw;
use Kern\Legislation\Events\LawDeletionChanged;
use Kern\Legislation\Models\SourceDocument;
use Kern\Legislation\Repositories\OfficialSourceRepository;
use Throwable;

/**
 * Removes a law in the background, then says over the socket that it is done ({@see LawDeletionChanged}).
 *
 * A consolidated act holds hundreds of thousands of provisions, and deleting them all is minutes of
 * work — longer than a request may be held open before the gateway gives up on it. So the controller
 * checks what would refuse the deletion (a fiscal value resting on it, a bond read from it), answers
 * `202` at once, and leaves the deleting to this job on the package's own queue, where a worker may
 * hold for as long as a consolidation read.
 *
 * The law is looked up again here, scoped to the legislation source as the controller scoped it: the
 * same table holds the exchange-rate history and the bank lists, which are not laws and are not deleted
 * this way. One already gone — asked for twice, or deleted between the request and this job — is a
 * `deleted` all the same: the asker wanted it gone, and it is.
 *
 * One try, no backoff: a deletion that failed rolled its transaction back whole (the law is untouched),
 * and retrying minutes of deleting on its own is not the answer — the asker is told it failed and may
 * ask again once whatever stopped it is seen to.
 */
final class DeleteLawJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly string $lawId,
        public readonly ?string $actorId,
        public readonly bool $ignore,
        public readonly ?string $userId,
    ) {
        $this->timeout = config()->integer('legislation.job_timeout_seconds');
        $this->onQueue(config()->string('legislation.queue'));
    }

    public function handle(DeleteLaw $delete, OfficialSourceRepository $sources): void
    {
        $law = SourceDocument::query()
            ->where('official_source_id', $sources->findIdForCode('LEGISLATIE'))
            ->find($this->lawId);

        if ($law instanceof SourceDocument) {
            $delete($law, $this->actorId, $this->ignore);
        }

        LawDeletionChanged::dispatch($this->lawId, $this->userId, 'deleted');
    }

    public function failed(?Throwable $exception): void
    {
        LawDeletionChanged::dispatch($this->lawId, $this->userId, 'failed');
    }
}
