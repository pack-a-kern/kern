<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Fx\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kern\TaxRomania\Fx\Actions\BackfillBnrYear as BackfillBnrYearAction;

/**
 * Thin `ShouldQueue` wrapper matching `BLUEPRINT.md` §7.3's named job — see
 * `Kern\TaxRomania\Fx\Actions\BackfillBnrYear` for the actual pipeline.
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
