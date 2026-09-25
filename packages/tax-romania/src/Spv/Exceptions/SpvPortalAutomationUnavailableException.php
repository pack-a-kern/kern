<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Exceptions;

use RuntimeException;

/**
 * `apps/spv-browser` answered `501 not_implemented` for a given portal action — the worker is
 * healthy, but that specific action requires observed ANAF SPV portal behaviour this codebase has
 * not verified and must not guess at (`docs/AGENTS.md` §1 rule 1,
 * `docs/reference/anaf-spv-integration.md`). Never retried automatically; this is a known,
 * permanent limitation until a future milestone observes the real portal.
 */
final class SpvPortalAutomationUnavailableException extends RuntimeException
{
    public static function forAction(string $action): self
    {
        return new self(
            "SPV portal automation for '{$action}' is not yet implemented — it requires "
            . 'observed ANAF portal behaviour (Milestone 10, docs/reference/anaf-spv-integration.md).',
        );
    }
}
