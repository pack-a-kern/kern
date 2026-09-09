<?php

declare(strict_types=1);

namespace App\Domain\Fx\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Fx\Actions\ImportBnrDailyRates`/`BackfillBnrYear` when the relevant
 * `config/sources.php` BNR endpoint (`BNR_DAILY_XML_URL`/`BNR_YEARLY_XML_URL_TEMPLATE`) is unset.
 *
 * This is deliberate, not a bug: `docs/reference/bnr-fx-endpoints.md` records that every
 * documented BNR XML URL 302-redirects to the homepage as of 2026-09-08, and `docs/AGENTS.md`
 * §1.1 forbids inventing an endpoint. Failing loudly here — instead of silently fetching nothing,
 * or worse, a guessed URL — is the whole point of keeping the endpoint in config rather than a
 * hardcoded constant.
 */
final class BnrEndpointNotConfiguredException extends RuntimeException
{
    public static function daily(): self
    {
        return new self(
            'BNR_DAILY_XML_URL is not configured (see docs/reference/bnr-fx-endpoints.md). '
            . 'Do not guess a URL — a human must confirm the real endpoint in a browser first.',
        );
    }

    public static function yearly(): self
    {
        return new self(
            'BNR_YEARLY_XML_URL_TEMPLATE is not configured (see docs/reference/bnr-fx-endpoints.md). '
            . 'Do not guess a URL — a human must confirm the real endpoint in a browser first.',
        );
    }
}
