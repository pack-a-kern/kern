<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Transport;

use DateTimeImmutable;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\DataTransferObjects\PortalAuthResult;
use Kern\TaxRomania\Spv\DataTransferObjects\SpvBrowserSession;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvSignInRequiredException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;
use Throwable;

/**
 * Drives `apps/browser-worker` by sending it the SPV playbook.
 *
 * The worker knows nothing about ANAF: the URLs, the selectors and the states a login can reach all
 * travel with the request, from `config/playbooks/anaf_spv.php`. So the portal changing its HTML is
 * an edit to that file — no deploy of the worker, and nothing here to change either.
 *
 * ## Why this implements the same contract as the old client
 *
 * `SpvBrowserSessionClient` already says what the three actions need: open a browser, send
 * credentials, send a code, destroy. Those are the right four questions whatever answers them, so
 * swapping the transport is a binding rather than a rewrite of everything above it.
 *
 * ## What the worker is told, and what it is not
 *
 * The playbook, the sequence to run, and the secrets that sequence names — nothing about which
 * taxpayer this is or why. A password reaches it as a value in one request and is held nowhere on
 * either side, which is what `SpvCredentialPolicy` requires and what makes the worker's own
 * ignorance worth keeping.
 */
final readonly class PlaybookBrowserSessionClient implements SpvBrowserSessionClient
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
        private string $baseUrl,
        private int $timeoutSeconds,
        private string $authToken,
    ) {
    }

    public function createSession(): SpvBrowserSession
    {
        $payload = $this->post('/v1/sessions', ['playbook' => $this->playbook()]);
        $id = $payload['session_id'] ?? null;
        $expires = $payload['expires_at'] ?? null;

        if (! is_string($id) || ! is_string($expires)) {
            throw SpvWorkerUnavailableException::malformedResponse();
        }

        try {
            return new SpvBrowserSession($id, new DateTimeImmutable($expires));
        } catch (Throwable $exception) {
            throw SpvWorkerUnavailableException::malformedResponse();
        }
    }

    public function submitCredentials(string $browserSessionRef, string $username, SensitiveString $password): PortalAuthResult
    {
        return $this->runSequence($browserSessionRef, 'signIn', [
            'username' => $username,
            'password' => $password->reveal(),
        ]);
    }

    public function submitChallengeCode(string $browserSessionRef, SensitiveString $code): PortalAuthResult
    {
        return $this->runSequence($browserSessionRef, 'challenge', ['code' => $code->reveal()]);
    }

    public function destroySession(string $browserSessionRef): void
    {
        $this->send('delete', '/v1/sessions/' . rawurlencode($browserSessionRef), []);
    }

    public function fetchInbox(string $browserSessionRef): array
    {
        $payload = $this->run($browserSessionRef, 'inbox', []);

        if (($payload['outcome'] ?? null) === 'session_lost') {
            throw SpvSignInRequiredException::sessionLost();
        }

        $data = $payload['data'] ?? null;
        $rows = is_array($data) ? ($data['messages'] ?? null) : null;

        if (! is_array($rows)) {
            // The sequence said it was signed in and produced nothing to read. That is the playbook's
            // selectors disagreeing with the page, not an empty inbox — and reporting zero messages
            // would be a wrong answer rather than a missing one.
            throw SpvWorkerUnavailableException::malformedResponse();
        }

        $messages = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $fields = [];

            foreach ($row as $name => $value) {
                if (is_string($name)) {
                    $fields[$name] = is_string($value) ? $value : null;
                }
            }

            $messages[] = $fields;
        }

        return $messages;
    }

    /**
     * One sequence of the playbook, and the state its outcome means.
     *
     * The playbook names its own outcomes, so the mapping lives here rather than in the worker —
     * which is the point: another portal would name them differently and need no change there.
     *
     * @param  array<string, string>  $secrets
     */
    private function runSequence(string $sessionRef, string $sequence, array $secrets): PortalAuthResult
    {
        $payload = $this->run($sessionRef, $sequence, $secrets);

        $outcome = is_string($payload['outcome'] ?? null) ? $payload['outcome'] : '';
        $message = is_string($payload['message'] ?? null) ? $payload['message'] : null;

        return match ($outcome) {
            'awaiting_challenge' => new PortalAuthResult(SpvSessionState::AWAITING_USER_CHALLENGE, null),
            'authenticated', 'completed' => new PortalAuthResult(SpvSessionState::AUTHENTICATED, null),
            // Everything else is a refusal the portal made, carrying whatever it said. Never the
            // worker's own error text: that can name a host, and a reason belongs to the portal.
            default => new PortalAuthResult(SpvSessionState::FAILED, $message),
        };
    }

    /**
     * Runs a sequence in an existing session and hands back what the worker said, whole.
     *
     * Separate from `runSequence` because the two callers want different halves: a sign-in wants the
     * outcome as a state, and the inbox wants the rows the run collected.
     *
     * @param  array<string, string>  $secrets
     * @return array<string, mixed>
     */
    private function run(string $sessionRef, string $sequence, array $secrets): array
    {
        return $this->post('/v1/sessions/' . rawurlencode($sessionRef) . '/run', [
            'playbook' => $this->playbook(),
            'sequence' => $sequence,
            'secrets' => $secrets,
        ]);
    }

    /** @return array<string, mixed> */
    private function playbook(): array
    {
        $playbook = $this->config->get('playbooks.anaf_spv');

        if (! is_array($playbook) || $playbook === []) {
            throw SpvWorkerUnavailableException::authenticationNotConfigured();
        }

        /** @var array<string, mixed> $playbook */
        return $playbook;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->send('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body): array
    {
        if ($this->baseUrl === '') {
            throw SpvWorkerUnavailableException::authenticationNotConfigured();
        }

        $request = $this->http->timeout($this->timeoutSeconds)->asJson();

        // Sent only when there is one. The worker decides whether it wants a token; a deployment
        // that trusts the network it is on configures neither side, and this must not refuse on its
        // behalf — which is what the client it replaces did.
        if ($this->authToken !== '') {
            $request = $request->withToken($this->authToken);
        }

        try {
            $response = $method === 'delete'
                ? $request->delete(rtrim($this->baseUrl, '/') . $path)
                : $request->post(rtrim($this->baseUrl, '/') . $path, $body);
        } catch (ConnectionException $exception) {
            throw SpvWorkerUnavailableException::requestFailed($exception);
        } catch (Throwable $exception) {
            throw SpvWorkerUnavailableException::requestFailed($exception);
        }

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->status() === 501) {
            // The worker saying a thing is not built, which is not the same fact as the worker
            // being unreachable — and the callers above act on the difference.
            throw SpvPortalAutomationUnavailableException::forAction('run');
        }

        if ($response->failed()) {
            throw SpvWorkerUnavailableException::unexpectedResponse($response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw SpvWorkerUnavailableException::malformedResponse();
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
