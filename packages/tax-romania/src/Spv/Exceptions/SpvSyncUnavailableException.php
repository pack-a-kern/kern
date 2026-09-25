<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An `Kern\TaxRomania\Spv\Contracts\SpvInboxSyncTransport` could not complete a sync — either
 * transport's own failure (certificate not configured, ANAF returned an error-shaped response, or
 * the browser transport's `SpvPortalAutomationUnavailableException`) is wrapped into this single
 * type so `Kern\TaxRomania\Spv\Actions\SyncSpvInbox` records one uniform `spv_sync_runs.status =
 * FAILED`, never a transport-specific column.
 */
final class SpvSyncUnavailableException extends RuntimeException
{
    public static function wrapping(Throwable $previous): self
    {
        return new self($previous->getMessage(), 0, $previous);
    }
}
