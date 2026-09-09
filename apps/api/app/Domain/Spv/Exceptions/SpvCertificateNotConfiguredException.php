<?php

declare(strict_types=1);

namespace App\Domain\Spv\Exceptions;

use RuntimeException;

/**
 * `App\Domain\Spv\Transport\AnafCertificateApiTransport` has no configured client certificate
 * (`config('spv.anaf_api.client_certificate_path')`). ANAF's documented `listaMesaje`/
 * `descarcare` web service is authenticated exclusively by qualified digital certificate
 * (`docs/reference/anaf-spv-integration.md`) — this transport must fail closed rather than ever
 * attempt an unauthenticated call.
 */
final class SpvCertificateNotConfiguredException extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'No qualified digital certificate is configured for the ANAF SPV web service '
            . '(spv.anaf_api.client_certificate_path).',
        );
    }
}
