<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;

/**
 * Binds {@see KeyManagementService} to the configured driver (`config/crypto.php`) and
 * registers {@see SubjectDekService} as a scoped (per-request) instance.
 *
 * `SubjectDekService` cannot be fully resolved by the container yet: its second constructor
 * argument, `Contracts\SubjectDekRepository`, has no implementation in this codebase — that
 * table (`tax_subjects`) belongs to the Identity/Subjects module. Registering the scoped
 * binding here is safe (Laravel does not eagerly resolve it); the first attempt to resolve
 * `SubjectDekService` before `SubjectDekRepository` is bound elsewhere throws Laravel's own
 * `BindingResolutionException`, which is the correct, honest failure mode — see
 * docs/handoff/005-crypto-core.md "What the identity agent must implement".
 */
final class KeyManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyManagementService::class, static function (Application $app): KeyManagementService {
            $driver = $app->make(ConfigRepository::class)->get('crypto.kms.driver');

            return match ($driver) {
                'openbao' => self::makeOpenBaoService($app),
                default => throw new InvalidArgumentException(sprintf(
                    'Unsupported crypto.kms.driver "%s" — expected "openbao". There is no ' .
                        'no-op/fake production driver by design (ADR 0002); tests construct ' .
                        'the in-memory fake directly instead of going through this binding.',
                    is_string($driver) ? $driver : gettype($driver),
                )),
            };
        });

        $this->app->scoped(SubjectDekService::class);
    }

    private static function makeOpenBaoService(Application $app): OpenBaoTransitKeyManagementService
    {
        $repository = $app->make(ConfigRepository::class);

        return new OpenBaoTransitKeyManagementService(
            $app->make(HttpFactory::class),
            self::stringConfig($repository, 'crypto.kms.openbao.address'),
            self::stringConfig($repository, 'crypto.kms.openbao.token'),
            self::stringConfig($repository, 'crypto.kms.openbao.transit_mount'),
            self::stringConfig($repository, 'crypto.kms.openbao.tax_subject_kek_name'),
            self::intConfig($repository, 'crypto.kms.openbao.timeout_seconds'),
        );
    }

    private static function stringConfig(ConfigRepository $config, string $key): string
    {
        $value = $config->get($key);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Missing or invalid config value "%s".', $key));
        }

        return $value;
    }

    private static function intConfig(ConfigRepository $config, string $key): int
    {
        $value = $config->get($key);

        if (! is_int($value)) {
            throw new InvalidArgumentException(sprintf('Missing or invalid config value "%s".', $key));
        }

        return $value;
    }
}
