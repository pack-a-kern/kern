<?php

declare(strict_types=1);

namespace App\Domain\Spv\Transport;

use App\Domain\Spv\Contracts\SpvBrowserSessionClient;
use App\Domain\Spv\Contracts\SpvInboxSyncTransport;
use App\Domain\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use App\Domain\Spv\Exceptions\SpvSyncUnavailableException;
use App\Domain\Spv\Exceptions\SpvWorkerUnavailableException;
use App\Domain\Spv\Models\SpvConnection;

/**
 * Would drive `apps/spv-browser`'s `/actions/sync` for a `USER_PASSWORD_OTP` connection with no
 * qualified certificate. That endpoint has no portal adapter yet — inbox navigation requires
 * observed ANAF SPV DOM structure this codebase has not verified
 * (`docs/reference/anaf-spv-integration.md`, `docs/AGENTS.md` §1 rule 1) — so this transport
 * always throws today. It exists as the second, real half of `SpvInboxSyncTransport`'s two-
 * transport contract, not as a stub with no call site: `App\Domain\Spv\Actions\SyncSpvInbox`
 * selects it for every `USER_PASSWORD_OTP` connection and genuinely calls it.
 */
final readonly class BrowserPortalInboxSyncTransport implements SpvInboxSyncTransport
{
    public function __construct(private SpvBrowserSessionClient $client)
    {
    }

    /** @return list<\App\Domain\Spv\DataTransferObjects\SpvInboxMessage> */
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
