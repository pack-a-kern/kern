<?php

declare(strict_types=1);

namespace App\Domain\Spv\Contracts;

use App\Domain\Spv\Exceptions\SpvSyncUnavailableException;
use App\Domain\Spv\Models\SpvConnection;

/**
 * `docs/reference/anaf-spv-integration.md`'s "real optimisation": inbox listing has an official,
 * documented API for certificate holders, strictly better than browser automation. Two
 * implementations, never conflated:
 *
 *  - {@see \App\Domain\Spv\Transport\AnafCertificateApiTransport} — ANAF's documented
 *    `listaMesaje`/`descarcare` web service. Real, implementable now; untestable against the
 *    live endpoint without a qualified certificate this environment does not have.
 *  - {@see \App\Domain\Spv\Transport\BrowserPortalInboxSyncTransport} — delegates to
 *    `apps/spv-browser`'s `/actions/sync`, which requires observed portal navigation this
 *    codebase has not built (`docs/AGENTS.md` §1 rule 1). Always throws
 *    {@see \App\Domain\Spv\Exceptions\SpvPortalAutomationUnavailableException} today.
 *
 * `App\Domain\Spv\Actions\SyncSpvInbox` picks one by `SpvConnection::$auth_method` — never
 * infers or guesses which transport a connection should use.
 */
interface SpvInboxSyncTransport
{
    /**
     * @return list<\App\Domain\Spv\DataTransferObjects\SpvInboxMessage>
     *
     * @throws SpvSyncUnavailableException
     */
    public function fetchMessages(SpvConnection $connection): array;
}
