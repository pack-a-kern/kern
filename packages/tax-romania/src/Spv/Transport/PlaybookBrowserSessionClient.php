<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Transport;

use DateTimeImmutable;
use Exception;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\DataTransferObjects\PortalAuthResult;
use Kern\TaxRomania\Spv\DataTransferObjects\SpvBrowserSession;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvRunFailedException;
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

        return ['rows' => $messages, 'expires_at' => $this->sessionExpiryIn($payload)];
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

        $expiresAt = $this->sessionExpiryIn($payload);

        return match ($outcome) {
            'awaiting_challenge' => new PortalAuthResult(SpvSessionState::AWAITING_USER_CHALLENGE, null, $expiresAt),
            'authenticated', 'completed' => new PortalAuthResult(SpvSessionState::AUTHENTICATED, null, $expiresAt),
            // Everything else is a refusal the portal made, carrying whatever it said. Never the
            // worker's own error text: that can name a host, and a reason belongs to the portal.
            default => new PortalAuthResult(SpvSessionState::FAILED, $message, $expiresAt),
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
        $response = $this->dispatch('post', '/v1/sessions/' . rawurlencode($sessionRef) . '/run', [
            'playbook' => $this->playbook(),
            'sequence' => $sequence,
            'secrets' => $secrets,
        ]);

        /*
         * The worker has no such session: it expired at its own TTL, or the worker was restarted and lost
         * every browser it held. Either way the sitting is over and somebody signs in again — the same
         * answer as the portal dropping the session, reached a different way, and better than the
         * "not reachable" a restarted worker used to produce about a worker that had just answered.
         *
         * Here rather than in `decode()`, because the same status means something else on the way out: a
         * 404 from `destroySession` is a browser already gone, which is what that call wanted.
         */
        if ($response->status() === 404) {
            throw SpvSignInRequiredException::sessionLost();
        }

        return $this->decode($response);
    }

    /**
     * The sitting's renewed expiry, as the worker reported it.
     *
     * Null rather than a guess when it is absent or unreadable: the alternative is this side inventing a
     * deadline the worker does not share, and a screen hiding an action for a browser that is still there.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sessionExpiryIn(array $payload): ?DateTimeImmutable
    {
        $raw = $payload['session_expires_at'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
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
        return $this->decode($this->dispatch($method, $path, $body));
    }

    /**
     * The worker's answer, undecoded.
     *
     * Separate because one status means different things depending on what was asked: a 404 from a run is
     * a sitting that no longer exists, and a 404 from a teardown is a browser already gone — which is what
     * the teardown wanted. `decode()` cannot tell them apart and should not try.
     *
     * @param  array<string, mixed>  $body
     */
    private function dispatch(string $method, string $path, array $body): Response
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

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->status() === 501) {
            // The worker saying a thing is not built, which is not the same fact as the worker
            // being unreachable — and the callers above act on the difference.
            throw SpvPortalAutomationUnavailableException::forAction('run');
        }

        /*
         * A run the worker attempted and could not finish, which it explains. Not the same fact as the
         * worker being unreachable, and reported as such until it cost an evening: the status code was
         * all that was read, so a timed-out selector arrived as a networking problem.
         */
        if ($response->status() === 502) {
            $body = $response->json();
            $message = is_array($body) && is_string($body['message'] ?? null) ? $body['message'] : '';

            throw SpvRunFailedException::because($message);
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
