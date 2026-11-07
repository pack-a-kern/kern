<?php

declare(strict_types=1);

namespace Kern\Platform\Http;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * A service inside the stack, as a client reaches it: where it is, the token it expects, and the CA
 * its certificate is checked against.
 *
 * Six clients used to set those three up by hand, and four of them read the processing service's
 * settings each on its own. Every one did check the certificate — this is where that stays true,
 * because a request built here cannot leave the check out.
 *
 * An address or a token left empty is the installation's decision (`compose.services/*.yaml` may be
 * left out), not a fault: {@see isConfigured()} and {@see hasToken()} let each caller refuse in its
 * own words — a document waits for extraction, a statement cannot be read — rather than here.
 */
final readonly class StackService
{
    private function __construct(
        private string $baseUrl,
        private string $token,
        private string|true $verify,
    ) {
    }

    /** The processing service: text extraction, statement reading, embeddings. */
    public static function processing(ConfigRepository $config): self
    {
        return self::fromConfig($config, 'services.processing.base_url', 'services.processing.auth_token', 'services.processing.ca_cert');
    }

    /** The browser worker, which drives whatever a playbook describes ({@see \Kern\Platform\BrowserWorker\BrowserWorkerClient}). */
    public static function browserWorker(ConfigRepository $config): self
    {
        return self::fromConfig($config, 'browser_worker.url', 'browser_worker.auth_token', 'browser_worker.ca_cert');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    /**
     * A request with its time limit, the certificate check and — where `$authenticated` and there is
     * one — the token. Unauthenticated only for a route that asks for none, such as `/health`: a
     * secret does not go where it buys nothing.
     */
    public function request(HttpFactory $http, int $timeoutSeconds, bool $authenticated = true): PendingRequest
    {
        $request = $http->timeout($timeoutSeconds)->withOptions(['verify' => $this->verify]);

        return $authenticated && $this->hasToken() ? $request->withToken($this->token) : $request;
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    private static function fromConfig(ConfigRepository $config, string $urlKey, string $tokenKey, string $caKey): self
    {
        $url = $config->get($urlKey);
        $token = $config->get($tokenKey);

        return new self(
            is_string($url) ? $url : '',
            is_string($token) ? $token : '',
            CertificateAuthority::verifyOption($config, $caKey),
        );
    }
}
