<?php

declare(strict_types=1);

namespace Kern\Legislation\Actions;

use Illuminate\Support\Facades\DB;
use Kern\Legislation\Enums\PortalSearchStatus;
use Kern\Legislation\Events\PortalSearchChanged;
use Kern\Legislation\Models\PortalSearchRun;

/**
 * Stops a running portal search. The page being read, if any, finishes and is not kept: its job finds
 * the search cancelled under the row's lock, and queues nothing after it. A search already finished is
 * left as it is.
 */
final readonly class CancelPortalSearch
{
    public function __invoke(PortalSearchRun $search): PortalSearchRun
    {
        return DB::transaction(function () use ($search): PortalSearchRun {
            $locked = PortalSearchRun::query()->lockForUpdate()->findOrFail($search->id);

            if ($locked->status === PortalSearchStatus::RUNNING) {
                $locked->fill(['status' => PortalSearchStatus::CANCELLED, 'finished_at' => now()])->save();
                PortalSearchChanged::dispatch($locked);
            }

            return $locked;
        });
    }
}
