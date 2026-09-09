<?php

declare(strict_types=1);

namespace App\Domain\Spv\Transport;

use App\Domain\Spv\Contracts\SpvInboxSyncTransport;
use App\Domain\Spv\DataTransferObjects\SpvInboxMessage;
use App\Domain\Spv\Enums\SpvAuthMethod;
use App\Domain\Spv\Exceptions\SpvCertificateNotConfiguredException;
use App\Domain\Spv\Exceptions\SpvSyncUnavailableException;
use App\Domain\Spv\Models\SpvConnection;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use Throwable;

/**
 * ANAF's documented `listaMesaje` web service (`docs/reference/anaf-spv-integration.md`, verified
 * 2026-09-08 from `https://static.anaf.ro/static/10/Anaf/Informatii_R/Prezentare_WS_SPV.txt`) —
 * authenticated exclusively by qualified digital certificate, never a username/password.
 *
 * **What is documented and used here:**
 * `GET {base_url}/listaMesaje?zile=<days>` over a TLS client-certificate connection, JSON
 * response `{"titlu": ..., "mesaje": [{"id", "detalii", "cif", "data_creare", "id_solicitare",
 * "tip"}, ...], "cnp", "cui", "serial"}`, or an error shaped `{"titlu": ..., "eroare": ...}` —
 * "an error is HTTP-200-shaped JSON, so response parsing must check for `eroare`, not just the
 * status code" (reference doc, verbatim).
 *
 * **What is NOT verified and this class does not guess**: the exact string format
 * `data_creare` is rendered in. `DateTimeImmutable`'s constructor is given the raw value as-is;
 * if ANAF's actual format is not one PHP recognises, this throws
 * {@see SpvSyncUnavailableException} rather than silently producing a wrong date — this
 * transport has never been exercised against the real endpoint (no certificate is available in
 * this environment), so failing loudly on an unrecognised format is the only honest behaviour.
 *
 * `zile` (the lookback window) is `spv.anaf_api.messages_window_days` — an engineering default,
 * not a value ANAF's documentation specifies.
 */
final readonly class AnafCertificateApiTransport implements SpvInboxSyncTransport
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private int $timeoutSeconds,
        private ?string $clientCertificatePath,
        private ?string $clientCertificatePassword,
        private int $messagesWindowDays,
    ) {
    }

    /** @return list<SpvInboxMessage> */
    public function fetchMessages(SpvConnection $connection): array
    {
        if ($connection->auth_method !== SpvAuthMethod::CERTIFICATE) {
            throw SpvSyncUnavailableException::wrapping(new InvalidArgumentException(
                'AnafCertificateApiTransport requires a CERTIFICATE-authenticated connection.',
            ));
        }

        if ($this->clientCertificatePath === null) {
            throw SpvSyncUnavailableException::wrapping(SpvCertificateNotConfiguredException::create());
        }

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withOptions(['cert' => [$this->clientCertificatePath, $this->clientCertificatePassword]])
                ->get(rtrim($this->baseUrl, '/') . '/listaMesaje', ['zile' => $this->messagesWindowDays]);
        } catch (ConnectionException $exception) {
            throw SpvSyncUnavailableException::wrapping($exception);
        } catch (Throwable $exception) {
            throw SpvSyncUnavailableException::wrapping($exception);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw SpvSyncUnavailableException::wrapping(new InvalidArgumentException(
                'ANAF listaMesaje returned a response that was not valid JSON.',
            ));
        }

        if (isset($payload['eroare'])) {
            throw SpvSyncUnavailableException::wrapping(new InvalidArgumentException(
                'ANAF listaMesaje returned an error: ' . (is_string($payload['eroare']) ? $payload['eroare'] : 'unknown'),
            ));
        }

        if (! isset($payload['mesaje']) || ! is_array($payload['mesaje'])) {
            throw SpvSyncUnavailableException::wrapping(new InvalidArgumentException(
                'ANAF listaMesaje response did not contain a "mesaje" array.',
            ));
        }

        return array_values(array_map($this->parseMessage(...), $payload['mesaje']));
    }

    private function parseMessage(mixed $raw): SpvInboxMessage
    {
        if (! is_array($raw)
            || ! isset($raw['id'], $raw['detalii'], $raw['data_creare'])
            || ! is_string($raw['data_creare'])
        ) {
            throw SpvSyncUnavailableException::wrapping(new InvalidArgumentException(
                'ANAF listaMesaje returned a message missing a required field.',
            ));
        }

        try {
            $receivedAt = new DateTimeImmutable($raw['data_creare']);
        } catch (Throwable $exception) {
            throw SpvSyncUnavailableException::wrapping($exception);
        }

        return new SpvInboxMessage(
            externalId: (string) $raw['id'],
            title: (string) $raw['detalii'],
            receivedAt: $receivedAt,
            metadata: [
                'cif' => isset($raw['cif']) ? (string) $raw['cif'] : null,
                'tip' => isset($raw['tip']) ? (string) $raw['tip'] : null,
                'id_solicitare' => isset($raw['id_solicitare']) ? (string) $raw['id_solicitare'] : null,
            ],
        );
    }
}
