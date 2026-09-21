<?php

declare(strict_types=1);

namespace App\Console\Commands\Banks;

use App\Domain\Banks\Actions\BackfillBanks;
use Illuminate\Console\Command;
use Throwable;

final class BackfillBanksCommand extends Command
{
    /** @var string */
    protected $signature = 'taxos:banks:backfill
        {--from= : First month to read, as YYYY-MM — the ECB archive goes back to 2017-01. Defaults to BANKS_BACKFILL_FROM.}';

    /** @var string */
    protected $description = 'Add the banks that closed or merged before the first import, from the ECB archived lists.';

    public function handle(BackfillBanks $backfill): int
    {
        $from = $this->option('from');

        if ($from !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from) !== 1) {
            $this->components->error('--from must be a month written YYYY-MM, e.g. 2017-01.');

            return self::FAILURE;
        }

        try {
            $summary = $backfill($from, function (string $month, ?int $banks): void {
                $this->line($banks === null ? "  {$month}: no list found" : "  {$month}: {$banks} banks listed");
            });
        } catch (Throwable $exception) {
            $this->components->error('Bank backfill failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Bank backfill complete: %d lists read (up to %s), %d closed banks added, %d already known dated back, %d added without a BIC%s.',
            $summary->snapshots,
            $summary->until,
            $summary->created,
            $summary->datedBack,
            $summary->withoutBic,
            $summary->missingMonths === [] ? '' : '; no list found for ' . implode(', ', $summary->missingMonths),
        ));

        return self::SUCCESS;
    }
}
