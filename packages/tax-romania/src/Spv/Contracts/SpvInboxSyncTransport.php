<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Contracts;

use Kern\TaxRomania\Spv\Exceptions\SpvSyncUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;

/**
 * `docs/reference/anaf-spv-integration.md`'s "real optimisation": inbox listing has an official,
 * documented API for certificate holders, strictly better than browser automation. Two
 * implementations, never conflated:
 *
 *  - {@see \Kern\TaxRomania\Spv\Transport\AnafCertificateApiTransport} — ANAF's documented
 *    `listaMesaje`/`descarcare` web service. Real, implementable now; untestable against the
 *    live endpoint without a qualified certificate this environment does not have.
 *  - {@see \Kern\TaxRomania\Spv\Transport\BrowserPortalInboxSyncTransport} — delegates to
 *    `apps/spv-browser`'s `/actions/sync`, which requires observed portal navigation this
 *    codebase has not built (`docs/AGENTS.md` §1 rule 1). Always throws
 *    {@see \Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException} today.
 *
 * `Kern\TaxRomania\Spv\Actions\SyncSpvInbox` picks one by `SpvConnection::$auth_method` — never
 * infers or guesses which transport a connection should use.
 */
interface SpvInboxSyncTransport
{
    /**
     * @return list<\Kern\TaxRomania\Spv\DataTransferObjects\SpvInboxMessage>
     *
     * @throws SpvSyncUnavailableException
     */
    public function fetchMessages(SpvConnection $connection): array;
}
