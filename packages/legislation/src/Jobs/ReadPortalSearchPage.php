<?php

declare(strict_types=1);

namespace Kern\Legislation\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kern\Legislation\Enums\PortalSearchFailure;
use Kern\Legislation\Enums\PortalSearchStatus;
use Kern\Legislation\Events\PortalSearchChanged;
use Kern\Legislation\Legislatie\PortalPage;
use Kern\Legislation\Legislatie\PortalSearch;
use Kern\Legislation\Models\PortalSearchRun;
use Throwable;

/**
 * Reads one page of a portal search, keeps the acts it asked for, and queues the next page unless this
 * one was the last (ADR 0024).
 *
 * ## Exactly the next page, once
 *
 * A page is read only while the search is running and this is the page after the last one read —
 * checked before the portal is asked, and again under the row's lock before anything is written. A
 * cancelled search, a job delivered twice, or one overtaken by a retry finds the record moved on and
 * does nothing.
 *
 * ## When a page will not answer
 *
 * The method asks again a few seconds apart on its own (`search_refused_*`); a page still refused or
 * failing — the first or any other — is retried by the queue after
 * `legislation.portal_search.retry_seconds`. Spent, the search ends: incomplete at this page, with what
 * the pages before found, or failed when it was the first. A refusal is never read as an answer: the
 * site answers a search with no result, and a page past the last, with an empty list, and its error page
 * at random to any of them (measured 2026-11-09).
 */
final class ReadPortalSearchPage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $searchId, public readonly int $page)
    {
        $this->tries = 1 + count($this->backoff());
        $this->timeout = config()->integer('legislation.portal_search.job_timeout_seconds');
        $this->onQueue(config()->string('legislation.portal_search.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        /** @var list<int> $pauses */
        $pauses = config()->array('legislation.portal_search.retry_seconds');

        return $pauses;
    }

    public function handle(PortalSearch $portal): void
    {
        $search = PortalSearchRun::query()->find($this->searchId);

        if (! $this->isNext($search)) {
            return;
        }

        $this->keep($portal, $portal->page($search->portalQuery(), $this->page));
    }

    /** The queue's retries are spent: the search ends where it got to, and says why. */
    public function failed(Throwable $exception): void
    {
        Log::warning('A portal search page would not answer; the search ends where it got to.', [
            'search_id' => $this->searchId,
            'page' => $this->page,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        $this->finish(PortalSearchFailure::of($exception));
    }

    /**
     * Adds the page's acts the query asked for and the search does not already hold, moves the search
     * on a page, and queues the next — once the record is committed, so the next job reads it moved on.
     */
    private function keep(PortalSearch $portal, PortalPage $page): void
    {
        DB::transaction(function () use ($portal, $page): void {
            $search = PortalSearchRun::query()->lockForUpdate()->find($this->searchId);

            if (! $this->isNext($search)) {
                return;
            }

            $query = $search->portalQuery();
            $acts = $search->acts;
            $held = array_flip(array_column($acts, 'link'));

            foreach ($page->acts as $act) {
                if ($query->keeps($act) && ! isset($held[$act->link])) {
                    $acts[] = $act->toArray();
                    $held[$act->link] = true;
                }
            }

            $last = $portal->isLast($page, $this->page, $search->lastLinks());

            $search->fill([
                'acts' => $acts,
                'last_links' => $page->links(),
                'pages_read' => $this->page,
                // The portal asked again within the page, and this job tried again before it answered.
                'retries' => $search->retries + $page->retries + $this->attempts() - 1,
                'status' => $last ? PortalSearchStatus::DONE : PortalSearchStatus::RUNNING,
                'finished_at' => $last ? now() : null,
            ])->save();

            PortalSearchChanged::dispatch($search);

            if (! $last) {
                self::dispatch($search->id, $this->page + 1)->afterCommit();
            }
        });
    }

    /** Ends a search that has not moved on: incomplete when a page before this one was read, failed when not. */
    private function finish(PortalSearchFailure $failure): void
    {
        DB::transaction(function () use ($failure): void {
            $search = PortalSearchRun::query()->lockForUpdate()->find($this->searchId);

            if (! $this->isNext($search)) {
                return;
            }

            $search->fill([
                'status' => $search->pages_read > 0 ? PortalSearchStatus::INCOMPLETE : PortalSearchStatus::FAILED,
                'failure' => $failure,
                'finished_at' => now(),
            ])->save();

            PortalSearchChanged::dispatch($search);
        });
    }

    /** @phpstan-assert-if-true PortalSearchRun $search */
    private function isNext(?PortalSearchRun $search): bool
    {
        return $search !== null
            && $search->status === PortalSearchStatus::RUNNING
            && $search->pages_read === $this->page - 1;
    }
}
