<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Transport;

use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Contracts\SpvInboxSyncTransport;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvSyncUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;

/**
 * Would drive `apps/spv-browser`'s `/actions/sync` for a `USER_PASSWORD_OTP` connection with no
 * qualified certificate. That endpoint has no portal adapter yet — inbox navigation requires
 * observed ANAF SPV DOM structure this codebase has not verified
 * (`docs/reference/anaf-spv-integration.md`, `docs/AGENTS.md` §1 rule 1) — so this transport
 * always throws today. It exists as the second, real half of `SpvInboxSyncTransport`'s two-
 * transport contract, not as a stub with no call site: `Kern\TaxRomania\Spv\Actions\SyncSpvInbox`
 * selects it for every `USER_PASSWORD_OTP` connection and genuinely calls it.
 */
final readonly class BrowserPortalInboxSyncTransport implements SpvInboxSyncTransport
{
    public function __construct(private SpvBrowserSessionClient $client)
    {
    }

    /** @return list<\Kern\TaxRomania\Spv\DataTransferObjects\SpvInboxMessage> */
    public function fetchMessages(SpvConnection $connection): array
    {
        $session = $this->client->createSession();

        try {
            $this->client->requestSync($session->id);
        } catch (SpvPortalAutomationUnavailableException|SpvWorkerUnavailableException $exception) {
            throw SpvSyncUnavailableException::wrapping($exception);
        } finally {
            $this->client->destroySession($session->id);
        }

        // Unreachable while requestSync() always throws — kept so this class's contract is
        // already correct the day a real portal adapter starts returning successfully.
        return [];
    }
}
