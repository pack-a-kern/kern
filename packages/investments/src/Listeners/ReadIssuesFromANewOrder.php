<?php

declare(strict_types=1);

namespace Kern\Investments\Listeners;

use Kern\Investments\Config\InvestmentsConfig;
use Kern\Investments\Jobs\ReadTreasuryOrder;
use Kern\Legislation\Events\LawConsolidationsRead;

/**
 * A law just read is, when its type and title say so, a programme's order: its issues are read from it,
 * on a queue of its own — so an order that does not read fails that job, never the law's own reading.
 * The import's own orders arrive here, and so does one a person added from the laws screen.
 */
final readonly class ReadIssuesFromANewOrder
{
    public function __construct(
        private InvestmentsConfig $config,
    ) {
    }

    public function handle(LawConsolidationsRead $event): void
    {
        if ($event->documentType !== $this->config->ordersActType) {
            return;
        }

        foreach ($this->config->programmes as $programme) {
            if ($programme->isTitled($event->title)) {
                ReadTreasuryOrder::dispatch($event->sourceDocumentId, $programme->programme)->onQueue($this->config->queue);

                return;
            }
        }
    }
}
