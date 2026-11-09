<?php

declare(strict_types=1);

namespace Kern\Investments\Console;

use Illuminate\Console\Command;
use Kern\Investments\Services\SubscriptionFiguresImport;
use Kern\Investments\Services\UnreadOrders;
use Throwable;

/**
 * The weekly government-bond task (ADR 0025 §5). The orders themselves are found by kern/legislation's law
 * watches, which this package declares; here, an order already a law but never read for its issues is
 * queued to be read, and the Ministry's subscription lists are read. Fails when the lists could not be.
 */
final class ImportGovernmentBondsCommand extends Command
{
    protected $signature = 'kern:investments:import-government-bonds';

    protected $description = 'Read the orders never read for their issues, and the Ministry of Finance\'s subscription lists.';

    public function handle(UnreadOrders $orders, SubscriptionFiguresImport $subscriptions): int
    {
        $this->line($orders->queue());

        try {
            foreach ($subscriptions->run() as $line) {
                $this->line($line);
            }
        } catch (Throwable $exception) {
            $this->line('FAILED subscriptions: ' . $exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
