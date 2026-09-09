<?php

declare(strict_types=1);

namespace App\Domain\Fx\Jobs;

use App\Domain\Fx\Actions\ImportBnrDailyRates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Thin `ShouldQueue` wrapper matching `BLUEPRINT.md` §7.2's named job — all behaviour lives in
 * {@see ImportBnrDailyRates}, which is what tests exercise directly. Scheduling this job
 * (`routes/console.php` `Schedule::job(...)`, per §7.2 "zile lucrătoare, după ora publicării BNR")
 * is outside this task's scope — see `docs/handoff/014-source-registry-bnr.md` "What is NOT done".
 */
final class FetchBnrDailyRates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(ImportBnrDailyRates $action): void
    {
        $action();
    }
}
