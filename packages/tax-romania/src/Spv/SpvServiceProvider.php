<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Transport\AnafCertificateApiTransport;
use Kern\TaxRomania\Spv\Transport\HttpSpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Transport\PlaybookBrowserSessionClient;

/** Wires Milestone 10's SPV automation module. See `docs/handoff/032-spv-automation.md`. */
final class SpvServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The worker is told what to do rather than having it built in.
         *
         * `PlaybookBrowserSessionClient` sends `config/playbooks/anaf_spv.php` with every request,
         * so ANAF's URLs and selectors live in this package and the worker stays a browser that
         * knows no website. `HttpSpvBrowserSessionClient` is what it replaces, and it is still here
         * because the routes it calls are: the two go together, and both leave together.
         */
        $this->app->bind(SpvBrowserSessionClient::class, static function (Application $app): SpvBrowserSessionClient {
            $config = $app->make(ConfigRepository::class);

            return new PlaybookBrowserSessionClient(
                $app->make(HttpFactory::class),
                $config,
                $config->string('spv.browser_worker.base_url'),
                $config->integer('spv.browser_worker.timeout_seconds'),
                $config->string('spv.browser_worker.auth_token'),
            );
        });

        $this->app->bind(HttpSpvBrowserSessionClient::class, static function (Application $app): HttpSpvBrowserSessionClient {
            $config = $app->make(ConfigRepository::class);

            return new HttpSpvBrowserSessionClient(
                $app->make(HttpFactory::class),
                $config->string('spv.browser_worker.base_url'),
                $config->integer('spv.browser_worker.timeout_seconds'),
                $config->string('spv.browser_worker.auth_token'),
            );
        });

        $this->app->bind(AnafCertificateApiTransport::class, static function (Application $app): AnafCertificateApiTransport {
            $config = $app->make(ConfigRepository::class);
            $certificatePath = $config->get('spv.anaf_api.client_certificate_path');
            $certificatePassword = $config->get('spv.anaf_api.client_certificate_password');

            return new AnafCertificateApiTransport(
                $app->make(HttpFactory::class),
                $config->string('spv.anaf_api.base_url'),
                $config->integer('spv.anaf_api.timeout_seconds'),
                is_string($certificatePath) ? $certificatePath : null,
                is_string($certificatePassword) ? $certificatePassword : null,
                $config->integer('spv.anaf_api.messages_window_days'),
            );
        });
    }
}
