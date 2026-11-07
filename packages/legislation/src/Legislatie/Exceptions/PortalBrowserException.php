<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie\Exceptions;

use Closure;
use Kern\Platform\BrowserWorker\Exceptions\BrowserActionNotBuiltException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserRunFailedException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserSessionGoneException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerAtCapacityException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerUnavailableException;
use RuntimeException;
use Throwable;

/**
 * The browser worker could not read the portal: busy, not there, or not finding on the page what the
 * playbook said would be there.
 *
 * Not the portal refusing — that is {@see PortalAnswerException} or a page's status — and not retried
 * the other way: the settings chose the browser, and this says the browser is what failed. A queued
 * import is retried on it like on any failure, which is right for a busy worker and a slow page.
 */
final class PortalBrowserException extends RuntimeException
{
    public const string BUSY = 'busy';

    public const string UNAVAILABLE = 'unavailable';

    public const string FAILED = 'failed';

    private function __construct(string $message, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Runs `$call`, saying the worker's failures as the portal's reader's.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public static function translate(Closure $call): mixed
    {
        try {
            return $call();
        } catch (BrowserWorkerAtCapacityException $exception) {
            throw new self('The browser worker is busy. Try again shortly.', self::BUSY, $exception);
        } catch (BrowserWorkerUnavailableException|BrowserActionNotBuiltException $exception) {
            throw new self('The browser worker is not available to read the portal.', self::UNAVAILABLE, $exception);
        } catch (BrowserRunFailedException|BrowserSessionGoneException $exception) {
            throw new self('The browser could not read the portal: ' . $exception->getMessage(), self::FAILED, $exception);
        }
    }
}
