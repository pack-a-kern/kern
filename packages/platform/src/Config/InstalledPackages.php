<?php

declare(strict_types=1);

namespace Kern\Platform\Config;

use JsonException;
use RuntimeException;

/**
 * The first-party packages installed into an application, discovered from composer rather than
 * listed anywhere.
 *
 * "First party" means: published under the same vendor as the application itself. The application's
 * own `composer.json` supplies that vendor, so nothing here names a vendor, and a fork that renames
 * every package needs no change. A third-party dependency is never returned, and a package added
 * tomorrow is found the moment composer installs it.
 *
 * The path returned is where composer put the package — `vendor/<vendor>/<name>` for a released
 * version, and, while the packages are still developed in this repository, the symlink composer's
 * path repository makes there, which resolves to `packages/<name>`. Both answer the same question:
 * where does this package keep its own files.
 */
final class InstalledPackages
{
    /**
     * @param  string  $basePath  the application's root — the directory holding its `composer.json`
     * @return array<string, string> package name => the directory composer installed it in
     */
    public static function firstParty(string $basePath): array
    {
        $vendor = self::vendorOf($basePath . '/composer.json');
        $installed = $basePath . '/vendor/composer/installed.json';

        if (! is_file($installed)) {
            throw new RuntimeException("Composer has not installed anything yet: {$installed} is missing.");
        }

        /** @var array{packages?: list<array{name?: string, install-path?: string}>} $manifest */
        $manifest = self::json($installed);
        $packages = [];

        foreach ($manifest['packages'] ?? [] as $package) {
            $name = $package['name'] ?? '';

            if (! str_starts_with($name, $vendor . '/')) {
                continue;
            }

            // `install-path` is relative to `vendor/composer/`, which is also the one place
            // composer writes it — deriving the path from the package name instead would be
            // wrong for anything an installer plugin puts elsewhere.
            $path = realpath($basePath . '/vendor/composer/' . ($package['install-path'] ?? ''));

            if ($path !== false) {
                $packages[$name] = $path;
            }
        }

        ksort($packages);

        return $packages;
    }

    private static function vendorOf(string $composerJson): string
    {
        /** @var array{name?: string} $application */
        $application = self::json($composerJson);
        $name = $application['name'] ?? '';
        $vendor = strstr($name, '/', true);

        if ($vendor === false || $vendor === '') {
            throw new RuntimeException("{$composerJson} has no vendor/name, so there is no vendor to call first-party.");
        }

        return $vendor;
    }

    /** @return array<string, mixed> */
    private static function json(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$file}.");
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$file} is not valid JSON.", previous: $e);
        }

        return $decoded;
    }
}
