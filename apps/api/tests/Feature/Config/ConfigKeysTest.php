<?php

declare(strict_types=1);

namespace Tests\Feature\Config\ConfigKeysTest;

use Illuminate\Support\Facades\Config;
use Kern\Platform\Config\EnvironmentVariables;
use Kern\Platform\Config\InstalledPackages;
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
    // `../../packages` is the local packages — the same spelling `phpstan.neon` and `make check-php`
    // use, and the reason this test still finds anything: almost every `config()` call in this
    // product is in one of them now, not in `app/`.
    $finder = Finder::create()
        ->files()
        ->name('*.php')
        ->exclude(['cache', 'vendor', 'node_modules'])
        ->in([
            base_path('app'),
            base_path('routes'),
            base_path('bootstrap'),
            base_path('database'),
            base_path('../../packages'),
        ]);

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
 * Every environment variable the application's own config reads is written down where an operator
 * will see it.
 *
 * An unset variable is the quietest failure a deployment has: `env()` returns the default, the
 * feature runs with a value nobody chose, and nothing is logged. When this was written, 72 were
 * documented nowhere.
 *
 * Laravel documents the variables of its own config files, so those are skipped — except the ones
 * this application added to them. A variable a package documents counts: the application installs
 * that package, `php artisan kern:env` prints both, and duplicating the sentence here is how the two
 * copies come to disagree.
 */
it('names every environment variable the application reads in .env.example', function (): void {
    $frameworkFiles = ['app', 'auth', 'broadcasting', 'cache', 'database', 'filesystems', 'hashing', 'logging', 'mail', 'queue', 'services', 'session', 'horizon', 'reverb', 'permission', 'webauthn'];
    $addedToFrameworkFiles = '/^(HORIZON_(AI|DEFAULT)_|AI_|PROCESSING_(URL|CA_CERT)$|INTERNAL_SERVICE_AUTH_TOKEN$|QUARANTINE_BUCKET$|AWS_CA_CERT$|DB_SSLROOTCERT$|REDIS_(SCHEME|CA_CERT)$|REVERB_(CA_CERT|TLS_CERT|TLS_KEY)$)/';

    $declared = EnvironmentVariables::declaredIn(base_path('.env.example'));

    foreach (InstalledPackages::firstParty(base_path()) as $path) {
        $declared = [...$declared, ...EnvironmentVariables::declaredIn($path . '/.env.example')];
    }

    $missing = [];

    foreach (EnvironmentVariables::readBy(config_path()) as $variable => $files) {
        $read = array_map(static fn (string $file): string => substr($file, 0, -4), $files);

        // Skipped only when every file reading it is one of Laravel's own.
        if (array_diff($read, $frameworkFiles) === [] && preg_match($addedToFrameworkFiles, $variable) !== 1) {
            continue;
        }

        if (! in_array($variable, $declared, true)) {
            $missing[] = $variable . ' (config/' . implode(', config/', $files) . ')';
        }
    }

    expect($missing)->toBe([]);
});

/*
 * And every package documents the variables *it* reads, in the `.env.example` it ships.
 *
 * A package is the only thing that knows what it reads, and it is installed into applications this
 * repository will never see — so the documentation has to travel with it rather than with any one
 * application. `php artisan kern:env` is what collects them, and is only as complete as this is.
 */
it('leaves every installed package to document the environment variables it reads', function (): void {
    $missing = [];
    $packages = InstalledPackages::firstParty(base_path());

    foreach ($packages as $name => $path) {
        $declared = EnvironmentVariables::declaredIn($path . '/.env.example');

        foreach (array_keys(EnvironmentVariables::readBy($path . '/config')) as $variable) {
            if (! in_array($variable, $declared, true)) {
                $missing[] = "{$name}: {$variable}";
            }
        }
    }

    // Not empty first: discovery that stopped finding packages would pass every run and prove nothing.
    expect(count($packages))->toBeGreaterThan(1)
        ->and($missing)->toBe([]);
});
