<?php

declare(strict_types=1);

namespace Tests\Feature\Config\ConfigKeysTest;

use Illuminate\Support\Facades\Config;
use Kern\Platform\Settings\Enums\SettingKey;
use Symfony\Component\Finder\Finder;

/**
 * Every configuration key the application reads exists.
 *
 * Reading a key that is not there returns null, or the default, and says nothing. So a renamed or
 * moved config file breaks nothing loudly: a timeout quietly becomes null, a model name becomes
 * empty, and the failure surfaces somewhere far from the rename. This reads the source for every
 * literal key, in each of the ways this codebase reads configuration, and fails naming the ones that
 * do not resolve.
 *
 * Keys built at runtime — an interpolated string — are not seen, and are rare enough to be reviewed
 * by hand.
 */
const CONFIG_READ = "/(?:\\bconfig\\(\\)->\\w+\\(|\\bconfig\\(|\\$(?:this->)?config->\\w+\\(|->\\w*Config\\(|\\bConfig::\\w+\\()\\s*'([a-z][a-z0-9_]*(?:\\.[A-Za-z0-9_]+)+)'/";

/** @return array<string, list<string>> key => the files reading it */
function configKeysReadInCode(): array
{
    $finder = Finder::create()
        ->files()
        ->name('*.php')
        ->exclude('cache')
        ->in([base_path('app'), base_path('routes'), base_path('bootstrap'), base_path('database')]);

    $keys = [];

    foreach ($finder as $file) {
        preg_match_all(CONFIG_READ, $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            $keys[$key][] = $file->getRelativePathname();
        }
    }

    return $keys;
}

it('reads no configuration key that does not exist', function (): void {
    $keys = configKeysReadInCode();

    $missing = [];

    foreach ($keys as $key => $files) {
        if (! Config::has($key)) {
            $missing[$key] = array_values(array_unique($files));
        }
    }

    // Not empty first: a pattern that stopped matching anything would pass every run and prove nothing.
    expect(count($keys))->toBeGreaterThan(50)
        ->and($missing)->toBe([]);
});

/*
 * A database setting overrides one config key, named in `SettingKey::configPath()` — a string in a
 * `match`, which the source scan above cannot see. A path pointing at a key that moved would make the
 * setting's fallback null and its screen show nothing, so every one is checked by name.
 */
it('points every database setting at a configuration key that exists', function (): void {
    $missing = [];

    foreach (SettingKey::cases() as $setting) {
        if (! Config::has($setting->configPath())) {
            $missing[$setting->name] = $setting->configPath();
        }
    }

    expect($missing)->toBe([]);
});

/*
 * Every environment variable the application's own config reads is named in `.env.example`.
 *
 * When this was written, 72 were documented nowhere, and 16 more sat in the root `.env.example`,
 * which never reaches Laravel for a variable compose does not pass on. Laravel documents the
 * variables of its own config files, so those are skipped — except for the ones this application
 * added to them.
 */
it('names every environment variable the application reads in .env.example', function (): void {
    $frameworkFiles = ['app', 'auth', 'broadcasting', 'cache', 'database', 'filesystems', 'hashing', 'logging', 'mail', 'queue', 'services', 'session', 'horizon', 'reverb', 'permission', 'webauthn'];
    $addedToFrameworkFiles = '/^(HORIZON_(AI|DEFAULT)_|AI_|PROCESSING_URL$|INTERNAL_SERVICE_AUTH_TOKEN$)/';
    $example = (string) file_get_contents(base_path('.env.example'));
    $missing = [];

    foreach (Finder::create()->files()->name('*.php')->in(config_path()) as $file) {
        $name = substr(str_replace('\\', '/', $file->getRelativePathname()), 0, -4);
        preg_match_all("/env\\('([A-Z0-9_]+)'/", $file->getContents(), $matches);

        foreach (array_unique($matches[1]) as $variable) {
            if (in_array($name, $frameworkFiles, true) && preg_match($addedToFrameworkFiles, $variable) !== 1) {
                continue;
            }

            if (preg_match('/^#?\\s?' . $variable . '=/m', $example) !== 1) {
                $missing[] = "{$variable} (config/{$name}.php)";
            }
        }
    }

    expect($missing)->toBe([]);
});
