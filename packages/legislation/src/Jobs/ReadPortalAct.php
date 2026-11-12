<?php

declare(strict_types=1);

namespace Kern\Legislation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Kern\Legislation\Actions\SyncLegislationDocument;
use Kern\Legislation\DataTransferObjects\PortalActToRead;
use Kern\Legislation\Events\PortalActRead;
use Throwable;

/**
 * One act of an "Import selected", read on the watches' queue — as a watch reads the acts it finds — so a
 * dozen chosen at once are read one after another, never holding up the reading of laws already started.
 * One per act until it starts: chosen twice, or by two people, it is read once. Tried once: an act the
 * portal will not give is that act's failure, kept among the failed jobs, and the others still read. Either
 * way the person who chose it is told ({@see PortalActRead}).
 */
final class ReadPortalAct implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(public readonly PortalActToRead $act, public readonly string $userId)
    {
        $this->timeout = config()->integer('legislation.job_timeout_seconds');
        $this->uniqueFor = $this->timeout;
        $this->onQueue(config()->string('legislation.watches.queue'));
    }

    public function uniqueId(): string
    {
        return $this->act->portalId;
    }

    public function handle(SyncLegislationDocument $sync): void
    {
        $sync($this->act->type, $this->act->number, $this->act->year, null, $this->act->portalId);

        PortalActRead::dispatch($this->act->portalId, $this->userId, true);
    }

    public function failed(Throwable $exception): void
    {
        PortalActRead::dispatch($this->act->portalId, $this->userId, false);
    }
}
