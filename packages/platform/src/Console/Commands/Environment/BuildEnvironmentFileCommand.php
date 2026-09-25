<?php

declare(strict_types=1);

namespace Kern\Platform\Console\Commands\Environment;

use Illuminate\Console\Command;
use Kern\Platform\Config\InstalledPackages;
use RuntimeException;

/**
 * Prints the environment file for this installation: the application's own `.env.example` followed
 * by the one every installed first-party package ships.
 *
 * A package documents the variables it reads, because it is the only thing that knows them — see
 * {@see \Kern\Platform\Config\EnvironmentVariables}. That leaves an operator with one file per
 * package instead of a file to fill in, which is what this command answers:
 *
 *     php artisan kern:env > .env
 *
 * Run it again after installing a package and the new variables are in the output, each with its
 * default and the sentence explaining what it changes. Nothing is written in place, so a `.env`
 * that already holds real credentials can never be overwritten by running this.
 */
final class BuildEnvironmentFileCommand extends Command
{
    protected $signature = 'kern:env';

    protected $description = "Print an environment file for this installation: the application's variables and every installed package's";

    public function handle(): int
    {
        $fragments = $this->fragments();

        $this->output->writeln([
            '# Assembled by `php artisan kern:env` from the .env.example of the application and of',
            '# every installed package, listed below. Fill in the values it leaves empty. Installing',
            '# another package brings variables of its own — re-run this and they are in the output,',
            '# each with the sentence explaining what it changes.',
            '#',
        ]);

        foreach (array_keys($fragments) as $source) {
            $this->output->writeln("#   {$source}");
        }

        foreach ($fragments as $contents) {
            $this->output->writeln(['', rtrim($contents)]);
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> a name for the source => its contents */
    private function fragments(): array
    {
        $fragments = ['the application' => $this->read($this->laravel->basePath('.env.example'))];

        foreach (InstalledPackages::firstParty($this->laravel->basePath()) as $name => $path) {
            $file = $path . '/.env.example';

            if (is_file($file)) {
                $fragments[$name] = $this->read($file);
            }
        }

        return $fragments;
    }

    private function read(string $file): string
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$file}.");
        }

        return $contents;
    }
}
