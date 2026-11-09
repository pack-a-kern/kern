<?php

declare(strict_types=1);

namespace Kern\Investments\Console;

use Illuminate\Console\Command;
use Kern\Investments\Services\OrderSearch;
use Kern\Investments\Services\SubscriptionFiguresImport;
use Throwable;

/**
 * The weekly government-bond import (ADR 0025 §5): each programme's new orders found and read as laws —
 * their issues are read from them as each is read — and the Ministry's subscription lists. One failing
 * does not stop the other; the run fails if either did, saying which.
 */
final class ImportGovernmentBondsCommand extends Command
{
    protected $signature = 'kern:investments:import-government-bonds';

    protected $description = 'Find the Ministry of Finance\'s new Fidelis and Tezaur orders, and read its subscription lists.';

    public function handle(OrderSearch $orders, SubscriptionFiguresImport $subscriptions): int
    {
        $lines = $orders->run();

        try {
            $lines = [...$lines, ...$subscriptions->run()];
        } catch (Throwable $exception) {
            $lines[] = 'FAILED subscriptions: ' . $exception->getMessage();
        }

        foreach ($lines as $line) {
            $this->line($line);
        }

        return array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'FAILED')) === [] ? self::SUCCESS : self::FAILURE;
    }
}
