<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

use Illuminate\Database\QueryException;
use Kern\Legislation\Exceptions\SourceDisabledException;
use Kern\Legislation\Legislatie\Exceptions\PortalBrowserException;
use League\Flysystem\FilesystemException;
use PDOException;
use Throwable;

/**
 * Why the reading of a law stopped, as a code a screen words in its own language — always a failure on
 * this installation's side, never the portal's. The portal's are retried and, spent, mark that one
 * consolidation; these would mark every consolidation of every law for a fault in none of them, so the
 * reading stops instead, nothing marked, until a person reads the law again.
 */
enum ReadingStop: string
{
    case STORAGE_UNAVAILABLE = 'storage_unavailable';

    case DATABASE_UNAVAILABLE = 'database_unavailable';

    case BROWSER_UNAVAILABLE = 'browser_unavailable';

    case SOURCE_DISABLED = 'source_disabled';

    case INTERNAL_ERROR = 'internal_error';

    /** The reason, read through the chain: storage arrives wrapped in the fetch or the parse that used it. */
    public static function of(Throwable $exception): self
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $reason = match (true) {
                $cause instanceof FilesystemException => self::STORAGE_UNAVAILABLE,
                $cause instanceof QueryException, $cause instanceof PDOException => self::DATABASE_UNAVAILABLE,
                $cause instanceof PortalBrowserException && $cause->reason === PortalBrowserException::UNAVAILABLE => self::BROWSER_UNAVAILABLE,
                $cause instanceof SourceDisabledException => self::SOURCE_DISABLED,
                default => null,
            };

            if ($reason !== null) {
                return $reason;
            }
        }

        return self::INTERNAL_ERROR;
    }
}
