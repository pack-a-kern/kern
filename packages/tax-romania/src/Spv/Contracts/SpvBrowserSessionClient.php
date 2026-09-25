<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Contracts;

use Kern\TaxRomania\Spv\DataTransferObjects\PortalAuthResult;
use Kern\TaxRomania\Spv\DataTransferObjects\SpvBrowserSession;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;

/**
 * The internal contract to `apps/spv-browser` (`BLUEPRINT.md` §13.4). One implementation
 * ({@see \Kern\TaxRomania\Spv\Transport\HttpSpvBrowserSessionClient}) — the interface exists so tests
 * exercise `Kern\TaxRomania\Spv\Actions\*` without a real HTTP call, the same seam
 * `Kern\Documents\Extraction\TextExtractionGateway` establishes for the Python service.
 *
 * `$password`/`$code` are `SensitiveString` all the way to this boundary — never a bare `string`
 * anywhere upstream (`BLUEPRINT.md` §13.2 steps 3/6).
 */
interface SpvBrowserSessionClient
{
    /** @throws SpvWorkerUnavailableException */
    public function createSession(): SpvBrowserSession;

    /**
     * @throws SpvPortalAutomationUnavailableException the worker has no portal adapter for this
     *                                                  action yet (today: always)
     * @throws SpvWorkerUnavailableException            the worker itself could not be reached
     */
    public function submitCredentials(string $browserSessionRef, string $username, SensitiveString $password): PortalAuthResult;

    /**
     * @throws SpvPortalAutomationUnavailableException
     * @throws SpvWorkerUnavailableException
     */
    public function submitChallengeCode(string $browserSessionRef, SensitiveString $code): PortalAuthResult;

    /** @throws SpvWorkerUnavailableException */
    public function destroySession(string $browserSessionRef): void;

    /**
     * @throws SpvPortalAutomationUnavailableException the worker has no portal adapter for inbox
     *                                                  sync yet (today: always — no ANAF SPV
     *                                                  portal navigation has been observed)
     * @throws SpvWorkerUnavailableException
     */
    public function requestSync(string $browserSessionRef): void;
}
