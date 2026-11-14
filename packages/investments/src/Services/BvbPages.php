<?php

declare(strict_types=1);

namespace Kern\Investments\Services;

use Illuminate\Database\Eloquent\Builder;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Enums\InstrumentStatus;
use Kern\Investments\Enums\Market;
use Kern\Investments\Jobs\ReadBvbInstrument;
use Kern\Investments\Models\FinancialInstrument;
use Kern\Investments\Models\IssuerProfile;

/**
 * After the day's import, each active instrument BVB lists whose page was never read, or was last read
 * longer ago than configured — a person's own reading counts — or whose past prices were never read,
 * queued to be read in the background, one every so many seconds, so BVB is never asked for them all at
 * once (ADR 0025 §5).
 */
final readonly class BvbPages
{
    public function __construct(
        private InvestmentsConfig $config,
    ) {
    }

    public function queue(): string
    {
        $bvb = $this->config->bvb;
        $fresh = IssuerProfile::query()->where('read_at', '>=', now()->subDays($bvb->pageRefreshDays))->select('issuer_key');
        $due = FinancialInstrument::query()
            ->where('market', Market::BVB)
            ->where('status', InstrumentStatus::ACTIVE)
            ->whereNotNull('symbol')
            ->where(static fn (Builder $query): Builder => $query->whereNull('history_read_at')->orWhereNotIn('symbol', $fresh))
            ->orderBy('symbol')
            ->get(['id']);

        foreach ($due->values() as $at => $instrument) {
            ReadBvbInstrument::dispatch($instrument->id)->onQueue($this->config->queue)->delay(now()->addSeconds($at * $bvb->spacingSeconds));
        }

        return sprintf('BVB pages: %d instruments queued to be read, one every %d seconds', $due->count(), $bvb->spacingSeconds);
    }
}
