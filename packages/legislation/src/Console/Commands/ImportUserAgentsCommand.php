<?php

declare(strict_types=1);

namespace Kern\Legislation\Console\Commands;

use Illuminate\Console\Command;
use Kern\Legislation\Services\UserAgentListImport;
use Throwable;

final class ImportUserAgentsCommand extends Command
{
    /** @var string */
    protected $signature = 'kern:sources:user-agents';

    /** @var string */
    protected $description = 'Read the published list of browsers\' User-Agents the Browser card offers.';

    public function handle(UserAgentListImport $import): int
    {
        try {
            $result = $import();
        } catch (Throwable $exception) {
            $this->components->error('User-Agent list not read: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            $result->changed ? 'User-Agent list of %s read: %d kept, %d left out.' : 'User-Agent list of %s already read: %d kept, %d left out.',
            $result->publishedAt->toDateString(),
            $result->kept,
            $result->skipped,
        ));

        return self::SUCCESS;
    }
}
