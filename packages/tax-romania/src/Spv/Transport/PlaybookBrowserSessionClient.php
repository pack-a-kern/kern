<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Transport;

use Closure;
use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Platform\BrowserWorker\BrowserWorkerClient;
use Kern\Platform\BrowserWorker\Exceptions\BrowserActionNotBuiltException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserRunFailedException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserSessionGoneException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerAtCapacityException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerUnavailableException;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\DataTransferObjects\PortalAuthResult;
use Kern\TaxRomania\Spv\DataTransferObjects\SpvBrowserSession;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use Kern\TaxRomania\Spv\Exceptions\SpvRunFailedException;
use Kern\TaxRomania\Spv\Exceptions\SpvSignInRequiredException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerAtCapacityException;
use Kern\TaxRomania\Spv\Exceptions\SpvWorkerUnavailableException;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;
use Throwable;

/**
 * Drives `apps/browser-worker` by sending it the SPV playbook, through the platform's
 * {@see BrowserWorkerClient} — which knows the worker's API, while this knows what SPV's answers mean.
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
        private BrowserWorkerClient $worker,
        private ConfigRepository $config,
    ) {
    }

    public function createSession(): SpvBrowserSession
    {
        $payload = $this->spv(fn (): array => $this->worker->openSession($this->playbook()));
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
        $this->spv(function () use ($browserSessionRef): array {
            $this->worker->closeSession($browserSessionRef);

            return [];
        });
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
        return $this->spv(fn (): array => $this->worker->runInSession($sessionRef, $this->playbook(), $sequence, $secrets));
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
     * A call to the worker, with its answers said in SPV's words.
     *
     * The callers above act on the difference between these: a full worker passes on its own, a refused
     * run carries the portal's reason, and a lost session means signing in again — which a restarted
     * worker used to report as "not reachable" about a worker that had just answered.
     *
     * @param  Closure(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function spv(Closure $call): array
    {
        try {
            return $call();
        } catch (BrowserSessionGoneException) {
            throw SpvSignInRequiredException::sessionLost();
        } catch (BrowserActionNotBuiltException) {
            throw SpvPortalAutomationUnavailableException::forAction('run');
        } catch (BrowserRunFailedException $exception) {
            throw SpvRunFailedException::because($exception->workerMessage);
        } catch (BrowserWorkerAtCapacityException) {
            throw SpvWorkerAtCapacityException::create();
        } catch (BrowserWorkerUnavailableException $exception) {
            throw match (true) {
                ! $this->worker->isConfigured() => SpvWorkerUnavailableException::authenticationNotConfigured(),
                $exception->getCode() > 0 => SpvWorkerUnavailableException::unexpectedResponse($exception->getCode()),
                $exception->getPrevious() instanceof Throwable => SpvWorkerUnavailableException::requestFailed($exception),
                default => SpvWorkerUnavailableException::malformedResponse(),
            };
        }
    }
}
