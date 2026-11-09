<?php

declare(strict_types=1);

namespace Kern\Legislation\Actions;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\Enums\PortalSearchStatus;
use Kern\Legislation\Events\PortalSearchChanged;
use Kern\Legislation\Jobs\ReadPortalSearchPage;
use Kern\Legislation\Legislatie\PortalQuery;
use Kern\Legislation\Models\PortalSearchRun;

/**
 * Starts a search of legislatie.just.ro, read a page a job (ADR 0024), and answers its record at once —
 * before the portal has been asked anything.
 *
 * An owner is whoever will read the results: a person, whose progress is broadcast to them, or none —
 * another package's job, which reads the record, or listens for `PortalSearchChanged`, when it likes.
 * Starting one stops the owner's searches still running — a person looks for one act at a time, and a
 * dialog closed and opened again should not leave the first search reading on — and removes every search
 * older than `legislation.portal_search.keep_hours`, an owner's or not, so nothing is kept that nobody
 * can still be reading and the table needs no schedule of its own to stay small.
 */
final readonly class StartPortalSearch
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function __invoke(PortalQuery $query, ?string $userId): PortalSearchRun
    {
        return DB::transaction(function () use ($query, $userId): PortalSearchRun {
            $this->forgetOld();

            if ($userId !== null) {
                $this->cancelRunning($userId);
            }

            $search = PortalSearchRun::query()->create([
                'user_id' => $userId,
                'query' => $query->toArray(),
                'status' => PortalSearchStatus::RUNNING,
                'pages_read' => 0,
                'acts' => [],
                'last_links' => [],
            ]);

            ReadPortalSearchPage::dispatch($search->id, 1)->afterCommit();

            return $search;
        });
    }

    /** Every search older than `keep_hours`, whoever started it. */
    private function forgetOld(): void
    {
        PortalSearchRun::query()
            ->where('created_at', '<', now()->subHours($this->config->integer('legislation.portal_search.keep_hours')))
            ->toBase()
            ->delete();
    }

    /** Cancels the owner's running searches, saying so to any screen still showing one. */
    private function cancelRunning(string $userId): void
    {
        $running = PortalSearchRun::query()
            ->where('user_id', $userId)
            ->where('status', PortalSearchStatus::RUNNING)
            ->lockForUpdate()
            ->get();

        foreach ($running as $search) {
            $search->fill(['status' => PortalSearchStatus::CANCELLED, 'finished_at' => now()])->save();
            PortalSearchChanged::dispatch($search);
        }
    }
}
