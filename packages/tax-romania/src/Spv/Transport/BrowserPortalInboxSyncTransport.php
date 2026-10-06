<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Transport;

use DateTimeImmutable;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Contracts\SpvInboxSyncTransport;
use Kern\TaxRomania\Spv\DataTransferObjects\SpvInboxMessage;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\SpvSignInRequiredException;
use Kern\TaxRomania\Spv\Exceptions\SpvSyncUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\Models\SpvConnection;
use Kern\TaxRomania\Spv\Models\SpvSession;

/**
 * Reads the inbox for a `USER_PASSWORD_OTP` connection, in the browser its sign-in left open.
 *
 * ## It reuses the session rather than opening one
 *
 * This used to call `createSession()` and read the inbox in a brand-new browser — which is not signed
 * in, so the playbook would have found a login page and extracted nothing from it. No selector could
 * have fixed that: the login lives in a running browser, and a new browser is a new login.
 *
 * Which is also why there is no unattended sync for this connection type and cannot be. ANAF emails a
 * one-time code; a code exists precisely so that nothing proceeds without a person. The sitting lasts
 * as long as the browser does, and when it ends somebody signs in again.
 *
 * ## It does not close the session afterwards
 *
 * The login is the expensive thing here — it cost somebody reading an email. A sync that tore the
 * browser down would make every read cost another code. The worker expires the session at its own TTL,
 * which is what bounds how long a signed-in browser is kept.
 */
final readonly class BrowserPortalInboxSyncTransport implements SpvInboxSyncTransport
{
    public function __construct(private SpvBrowserSessionClient $client)
    {
    }

    /** @return list<SpvInboxMessage> */
    public function fetchMessages(SpvConnection $connection): array
    {
        $session = $this->liveSessionFor($connection);

        try {
            $rows = $this->client->fetchInbox($session->browser_session_ref);
        } catch (SpvWorkerUnavailableException $exception) {
            throw SpvSyncUnavailableException::wrapping($exception);
        }

        $messages = [];

        foreach ($rows as $row) {
            $message = $this->toMessage($row);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * The signed-in browser for this connection, or a refusal naming what to do about it.
     *
     * `expires_at` is checked here rather than trusted: the worker drops a session at its own TTL
     * without telling anybody, so a row that still says AUTHENTICATED may describe a browser that no
     * longer exists. Asking the worker and being told "no such session" would work too, and would cost
     * a round trip to learn something already written down.
     */
    private function liveSessionFor(SpvConnection $connection): SpvSession
    {
        $session = SpvSession::query()
            ->where('connection_id', $connection->id)
            ->where('state', SpvSessionState::AUTHENTICATED)
            ->whereNull('destroyed_at')
            ->where('expires_at', '>', now('UTC'))
            ->orderByDesc('created_at')
            ->first();

        if (! $session instanceof SpvSession) {
            throw SpvSignInRequiredException::noLiveSession();
        }

        return $session;
    }

    /**
     * One portal row as an `SpvInboxMessage`, or null when it is not one.
     *
     * The same three fields the certificate API's rows carry, because the playbook extracts into ANAF's
     * own names — so a message means the same thing whichever transport fetched it. A row missing its id
     * or its date is skipped rather than guessed at: a message with no identity cannot be deduplicated,
     * and one with no date cannot be placed in a tax year.
     *
     * @param  array<string, string|null>  $row
     */
    private function toMessage(array $row): ?SpvInboxMessage
    {
        $id = $row['id'] ?? null;
        $detail = $row['detalii'] ?? null;
        $createdAt = $row['data_creare'] ?? null;

        if ($id === null || $id === '' || $detail === null || $createdAt === null) {
            return null;
        }

        try {
            $receivedAt = new DateTimeImmutable($createdAt);
        } catch (\Exception) {
            // The portal rendered a date this cannot read. Skipped for the same reason as a missing one.
            return null;
        }

        $metadata = [];

        foreach (['cif'] as $extra) {
            if (($row[$extra] ?? null) !== null) {
                $metadata[$extra] = $row[$extra];
            }
        }

        return new SpvInboxMessage(
            externalId: $id,
            title: $detail,
            receivedAt: $receivedAt,
            metadata: $metadata,
        );
    }
}
