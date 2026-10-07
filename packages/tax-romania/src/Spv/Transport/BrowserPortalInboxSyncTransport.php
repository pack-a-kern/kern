<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Transport;

use Illuminate\Support\Carbon;
use DateTimeImmutable;
use DateTimeZone;
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
            $page = $this->client->fetchInbox($session->browser_session_ref);
        } catch (SpvWorkerUnavailableException $exception) {
            throw SpvSyncUnavailableException::wrapping($exception);
        } catch (SpvSignInRequiredException $exception) {
            // Closed as we learn of it, rather than waiting for the scheduled sweep: the next thing the
            // person does is look at the screen, and it should already know the sitting ended.
            $session->destroyed_at = now('UTC');
            $session->state = SpvSessionState::FAILED;
            $session->save();

            throw $exception;
        }

        // Reading the inbox is use, so the sitting has been pushed forward and the row has to be told.
        // Otherwise a person checking every ten minutes watches the action disappear under them.
        if ($page['expires_at'] !== null) {
            $session->expires_at = Carbon::instance($page['expires_at']);
            $session->save();
        }

        $messages = [];

        foreach ($page['rows'] as $row) {
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
     * ## The identity is derived, because the page has none
     *
     * Measured rather than assumed, against a real inbox of twenty messages: `Id Solicitare` is blank on
     * three of them — messages ANAF sent rather than answered — so keying on it silently dropped those
     * three. And nothing else on the row identifies it either. PrimeFaces emits no `data-rk` here, the
     * rows carry no `id`, and the download link is `submitForm('form','form:MesajeTable:0:j_id…')` whose
     * only distinguishing part is the row *index*, which shifts as messages arrive.
     *
     * So the identity is a fingerprint of the fields that describe the message. The date carries seconds,
     * which makes collisions between genuinely different messages implausible, and the whole tuple is
     * stable for as long as ANAF leaves the row alone. If ANAF ever edits a description the message will
     * look new — which is wrong in principle and arguably right in practice, since the row changed.
     *
     * **Prefixed `web:` deliberately.** The certificate API returns real ids, and
     * {@see AnafCertificateApiTransport} uses them. Two transports cannot agree on an identity when only
     * one of them is given one, so a taxpayer who switched from a password to a certificate would import
     * the same message twice. The prefix makes that visible as two obviously different keys rather than a
     * coincidence nobody notices.
     *
     * @param  array<string, string|null>  $row
     */
    private function toMessage(array $row): ?SpvInboxMessage
    {
        $detail = $this->trimmed($row, 'detalii');
        $renderedDate = $this->trimmed($row, 'data_creare');

        if ($detail === null || $renderedDate === null) {
            return null;
        }

        $receivedAt = $this->parsePortalDate($renderedDate);

        if ($receivedAt === null) {
            return null;
        }

        $metadata = [];

        foreach (['cif', 'tip'] as $extra) {
            $value = $this->trimmed($row, $extra);

            if ($value !== null) {
                $metadata[$extra] = $value;
            }
        }

        // Kept because it is useful and because it is what a person reading ANAF's own screen sees, even
        // though it cannot be the identity.
        $solicitare = $this->trimmed($row, 'id');

        if ($solicitare !== null) {
            $metadata['id_solicitare'] = $solicitare;
        }

        return new SpvInboxMessage(
            externalId: $this->fingerprint($renderedDate, $detail, $metadata),
            title: $detail,
            receivedAt: $receivedAt,
            metadata: $metadata,
        );
    }

    /**
     * The portal's date, in the portal's timezone.
     *
     * An exact format rather than `new DateTimeImmutable($value)`: handed "15.09.2026" that constructor
     * reads a European date correctly and handed "09/15/2026" it would read an American one, and neither
     * announces which it chose. `createFromFormat` fails instead, and a row whose date cannot be read is
     * skipped rather than filed in a year nobody chose.
     *
     * Seconds are optional because a portal is free to render either, and `!` resets the unspecified
     * fields so a date-only value becomes midnight rather than the time this code happened to run.
     */
    private function parsePortalDate(string $rendered): ?DateTimeImmutable
    {
        $configured = config('spv.portal_timezone');
        $timezone = new DateTimeZone(is_string($configured) && $configured !== '' ? $configured : 'Europe/Bucharest');

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', '!d.m.Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $rendered, $timezone);

            if ($parsed !== false) {
                /*
                 * Converted to UTC here, not left carrying `+02:00`.
                 *
                 * Postgres `timestamptz` would honour the offset; sqlite has no such type and stores
                 * whatever string the model formats, then reads it back as UTC — so the same message
                 * lands three hours apart on the suite's two lanes, and the one that is wrong is the
                 * one that looks fine. An instant has no timezone; only its rendering does.
                 */
                return $parsed->setTimezone(new DateTimeZone('UTC'));
            }
        }

        return null;
    }

    /**
     * A stable key for a message the portal does not name.
     *
     * Over the rendered date and the description, plus the metadata that distinguishes two otherwise
     * identical rows — the taxpayer the message is about, and what kind of document it is. Hashed rather
     * than concatenated so the key has one length whatever the description, and so a description is not
     * sitting in a column the blind index is computed over.
     *
     * @param  array<string, string>  $metadata
     */
    private function fingerprint(string $renderedDate, string $detail, array $metadata): string
    {
        ksort($metadata);

        $material = implode('|', [$renderedDate, $detail, ...array_values($metadata)]);

        return 'web:' . hash('sha256', $material);
    }

    /** @param array<string, string|null> $row */
    private function trimmed(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
