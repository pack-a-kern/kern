<?php

declare(strict_types=1);

namespace App\Domain\Fx\Jobs;

use App\Domain\Fx\Actions\BackfillBnrYear as BackfillBnrYearAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Thin `ShouldQueue` wrapper matching `BLUEPRINT.md` §7.3's named job — see
 * `App\Domain\Fx\Actions\BackfillBnrYear` for the actual pipeline.
 */
final class BackfillBnrYear implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $year,
    ) {
    }

    public function handle(BackfillBnrYearAction $action): void
    {
        $action($this->year);
    }
}
