<?php

declare(strict_types=1);

namespace Kern\Platform\Config;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Which environment variables a unit's configuration reads, and which its `.env.example` writes
 * down.
 *
 * An unset variable is the quietest failure a deployment has: `env()` returns the default, the
 * feature runs with a value nobody chose, and nothing is logged. The only defence is that every
 * variable is written down where an operator will see it — which is what
 * `tests/Feature/Config/ConfigKeysTest.php` asserts, for the application and for each installed
 * package, using this class.
 *
 * "A unit" is an application or a package: both keep their configuration in a `config/` directory
 * and their documentation in a `.env.example` beside it, so both are read the same way here.
 */
final class EnvironmentVariables
{
    /**
     * `env('NAME'` — the only spelling this codebase uses, and the only one Pint leaves. A name
     * built at runtime is not seen, which is why none is built.
     */
    private const string READ = "/env\\('([A-Z][A-Z0-9_]*)'/";

    /**
     * A name is declared whether or not it is commented out: a variable with a default in its
     * config file is documented by naming it, not by repeating the default.
     */
    private const string DECLARED = '/^#?\s*([A-Z][A-Z0-9_]*)=/m';

    /**
     * @return array<string, list<string>> variable => the config files reading it, relative to
     *                                     `$configDirectory`
     */
    public static function readBy(string $configDirectory): array
    {
        $variables = [];

        foreach (self::phpFilesIn($configDirectory) as $relativePath => $contents) {
            preg_match_all(self::READ, $contents, $matches);

            foreach (array_unique($matches[1]) as $variable) {
                $variables[$variable][] = $relativePath;
            }
        }

        ksort($variables);

        return $variables;
    }

    /** @return list<string> */
    public static function declaredIn(string $exampleFile): array
    {
        if (! is_file($exampleFile)) {
            return [];
        }

        $contents = file_get_contents($exampleFile);

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$exampleFile}.");
        }

        preg_match_all(self::DECLARED, $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return iterable<string, string> relative path => contents */
    private static function phpFilesIn(string $directory): iterable
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                throw new RuntimeException("Cannot read {$file->getPathname()}.");
            }

            yield ltrim(str_replace($directory, '', $file->getPathname()), '/') => $contents;
        }
    }
}
