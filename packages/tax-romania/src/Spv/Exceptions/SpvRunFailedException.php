<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Exceptions;

use RuntimeException;

/**
 * The worker ran the playbook and the run broke — a step timed out, or a selector matched nothing.
 *
 * Distinct from `SpvWorkerUnavailableException`, and the distinction cost real time: a run that failed
 * used to be reported as "the browser worker is not reachable", because the only thing read off the
 * response was its status code. The worker was reachable, had launched a browser, and had answered with
 * an explanation — which was thrown away. Somebody then went looking at networking for a selector
 * problem.
 *
 * The worker's own words are kept here for the log and for whoever is fixing the playbook. They are
 * already redacted on that side, and they are diagnostic rather than something to show a person: "the
 * page showed none of: authenticated, failed" names a playbook's outcomes, not anything a taxpayer did.
 * The screen gets a translated reason instead.
 */
final class SpvRunFailedException extends RuntimeException
{
    public static function because(string $workerMessage): self
    {
        return new self(
            $workerMessage === ''
                ? 'The browser worker could not complete the run.'
                : "The browser worker could not complete the run: {$workerMessage}",
        );
    }
}
