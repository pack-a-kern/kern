<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Fetching;

use App\Domain\TaxKnowledge\Config\OfficialSourceRegistry;
use App\Domain\TaxKnowledge\DataTransferObjects\SourceDefinition;
use App\Domain\TaxKnowledge\Exceptions\SourceDisabledException;
use App\Domain\TaxKnowledge\Fetching\Exceptions\FetchSizeExceededException;
use App\Domain\TaxKnowledge\Fetching\Exceptions\HostNotAllowedException;
use App\Domain\TaxKnowledge\Fetching\Exceptions\TooManyRedirectsException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * The single place this application makes an outbound HTTP request to an official source
 * (`BLUEPRINT.md` §4.1 SSRF control, §7.2/§9 fetcher). Every host — the URL a caller supplies, and
 * every redirect target along the way — is checked against that source's
 * `config/sources.php` allowlist before a request is made to it. A redirect to a host off the
 * allowlist is refused, never silently followed.
 *
 * Constructor-injects `Illuminate\Http\Client\Factory` (not the `Http` facade), matching the
 * precedent `docs/handoff/005-crypto-core.md` set for `OpenBaoTransitKeyManagementService` — a
 * plain instance is directly testable, and `Http::fake()` still works when this class is resolved
 * through the container (the facade swaps the same underlying singleton).
 */
final readonly class AllowlistedHttpFetcher
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private HttpFactory $http,
        private OfficialSourceRegistry $registry,
        private int $maxRedirects,
        private int $maxResponseBytes,
        private int $timeoutSeconds,
    ) {
    }

    public function fetch(string $sourceCode, string $url): FetchedDocument
    {
        $definition = $this->registry->find($sourceCode);

        if (! $definition->enabled) {
            throw SourceDisabledException::forCode($sourceCode);
        }

        $currentUrl = $url;
        $hopsRemaining = $this->maxRedirects;

        while (true) {
            $this->assertAllowedUrl($definition, $currentUrl);

            $response = $this->http
                ->withHeaders([
                    'User-Agent' => $definition->userAgent,
                    'Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.5',
                ])
                ->timeout($this->timeoutSeconds)
                ->withOptions(['allow_redirects' => false])
                ->get($currentUrl);

            if (in_array($response->status(), self::REDIRECT_STATUSES, true)) {
                if ($hopsRemaining <= 0) {
                    throw TooManyRedirectsException::forUrl($url);
                }

                $currentUrl = $this->resolveRedirectTarget($currentUrl, $response->header('Location'));
                $hopsRemaining--;

                continue;
            }

            $this->assertDeclaredSizeWithinLimit($currentUrl, $response->header('Content-Length'));

            $body = $response->body();
            $this->assertActualSizeWithinLimit($currentUrl, strlen($body));

            return new FetchedDocument(
                body: $body,
                contentType: $response->header('Content-Type'),
                finalUrl: $currentUrl,
                statusCode: $response->status(),
            );
        }
    }

    private function assertAllowedUrl(SourceDefinition $definition, string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || $host === '' || ! $definition->allowsHost($host)) {
            throw HostNotAllowedException::forUrl($url);
        }
    }

    /**
     * `Location` may legally be relative (RFC 7231 §7.1.2). Only an absolute `https://` URL or a
     * root-relative path against the current URL's own scheme+host is resolved — anything else is
     * rejected rather than guessed at, since a same-host relative redirect is the only relative
     * form BNR/ANAF/legislatie.just.ro would plausibly ever send.
     */
    private function resolveRedirectTarget(string $currentUrl, ?string $location): string
    {
        if ($location === null || $location === '') {
            throw new RuntimeException(sprintf('Redirect response from "%s" carried no Location header.', $currentUrl));
        }

        if (str_starts_with($location, 'https://') || str_starts_with($location, 'http://')) {
            return $location;
        }

        if (str_starts_with($location, '/')) {
            $scheme = parse_url($currentUrl, PHP_URL_SCHEME);
            $host = parse_url($currentUrl, PHP_URL_HOST);

            if (! is_string($scheme) || ! is_string($host)) {
                throw new RuntimeException(sprintf('Cannot resolve relative redirect "%s" against "%s".', $location, $currentUrl));
            }

            return sprintf('%s://%s%s', $scheme, $host, $location);
        }

        throw new RuntimeException(sprintf('Unsupported relative redirect target "%s" from "%s".', $location, $currentUrl));
    }

    private function assertDeclaredSizeWithinLimit(string $url, ?string $contentLength): void
    {
        if ($contentLength === null || ! ctype_digit($contentLength)) {
            return;
        }

        if ((int) $contentLength > $this->maxResponseBytes) {
            throw FetchSizeExceededException::forUrl($url, $this->maxResponseBytes);
        }
    }

    private function assertActualSizeWithinLimit(string $url, int $actualBytes): void
    {
        if ($actualBytes > $this->maxResponseBytes) {
            throw FetchSizeExceededException::forUrl($url, $this->maxResponseBytes);
        }
    }
}
