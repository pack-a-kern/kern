<?php

declare(strict_types=1);

namespace Kern\Platform\KeyManagement;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Kern\Platform\Config\RequiredConfig;
use Kern\Platform\Http\CertificateAuthority;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Kern\Platform\Settings\Infrastructure\SystemDekRepository;

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
 *
 * {@see DekRewrapService} is assembled from whatever is tagged {@see DekRewrapService::TAG}, so a
 * package contributes its own DEK store without this file naming it. Only
 * `Kern\Platform\Settings\Infrastructure\SystemDekRepository` is tagged here — it is this
 * package's own; `tax_subjects`/`users` are tagged by the module that owns them.
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

        // Not scoped and not a singleton: a rewrap run is a command, resolved once, and holding the
        // service open past it would keep the tagged stores alive for no reason.
        $this->app->tag([SystemDekRepository::class], DekRewrapService::TAG);

        $this->app->bind(DekRewrapService::class, static function (Application $app): DekRewrapService {
            /** @var iterable<\Kern\Platform\KeyManagement\Contracts\DekRewrapRepository> $stores */
            $stores = $app->tagged(DekRewrapService::TAG);

            return new DekRewrapService($app->make(KeyManagementService::class), $stores);
        });
    }

    private static function makeOpenBaoService(Application $app): OpenBaoTransitKeyManagementService
    {
        $config = $app->make(RequiredConfig::class);
        $token = $config->optionalString('crypto.kms.openbao.token');
        $tokenFile = $config->optionalString('crypto.kms.openbao.token_file');

        // Refused while building, as every other missing value is, rather than on the first request.
        if ($token === null && $tokenFile === null) {
            throw new InvalidArgumentException(
                'Missing config value: one of "crypto.kms.openbao.token" or "crypto.kms.openbao.token_file".',
            );
        }

        return new OpenBaoTransitKeyManagementService(
            $app->make(HttpFactory::class),
            $config->string('crypto.kms.openbao.address'),
            $token,
            $tokenFile,
            CertificateAuthority::verifyOption($app->make(ConfigRepository::class), 'crypto.kms.openbao.ca_cert'),
            $config->string('crypto.kms.openbao.transit_mount'),
            $config->string('crypto.kms.openbao.tax_subject_kek_name'),
            $config->string('crypto.kms.openbao.blind_index_key_name'),
            $config->positiveInt('crypto.kms.openbao.timeout_seconds'),
            $config->positiveInt('crypto.kms.openbao.hmac_batch_size'),
        );
    }
}
