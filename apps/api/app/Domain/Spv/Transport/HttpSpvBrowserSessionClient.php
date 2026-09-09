<?php

declare(strict_types=1);

namespace App\Domain\Spv\Transport;

use App\Domain\Spv\Contracts\SpvBrowserSessionClient;
use App\Domain\Spv\DataTransferObjects\PortalAuthResult;
use App\Domain\Spv\DataTransferObjects\SpvBrowserSession;
use App\Domain\Spv\Enums\SpvSessionState;
use App\Domain\Spv\Exceptions\SpvPortalAutomationUnavailableException;
use App\Domain\Spv\Exceptions\SpvWorkerUnavailableException;
use App\Domain\Spv\ValueObjects\SensitiveString;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Calls `apps/spv-browser` over the internal network (`BLUEPRINT.md` §13.4). Structurally identical
 * to `App\Domain\Documents\Extraction\HttpTextExtractionGateway` — see that class's docblock for
 * the shared shape (bearer-token internal auth, `TextExtractionUnavailableException`-vs-
 * `DocumentRejectedByExtractionException` split mirrored here as
 * `SpvWorkerUnavailableException`-vs-`SpvPortalAutomationUnavailableException`).
 *
 * `$password`/`$code` are revealed exactly once, at the point of building this HTTP request's
 * body, and never assigned to a variable that outlives this method call.
 */
final readonly class HttpSpvBrowserSessionClient implements SpvBrowserSessionClient
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private int $timeoutSeconds,
        private string $authToken,
    ) {
    }

    public function createSession(): SpvBrowserSession
    {
        $response = $this->post('/v1/spv/sessions');

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['id'], $payload['expiresAt']) || ! is_string($payload['id']) || ! is_string($payload['expiresAt'])) {
            throw SpvWorkerUnavailableException::malformedResponse();
        }

        return new SpvBrowserSession($payload['id'], new DateTimeImmutable($payload['expiresAt']));
    }

    public function submitCredentials(string $browserSessionRef, string $username, SensitiveString $password): PortalAuthResult
    {
        $response = $this->post("/v1/spv/sessions/{$browserSessionRef}/credentials", [
            'username' => $username,
            'password' => $password->reveal(),
        ]);

        return $this->parseAuthResult($response, 'credentials');
    }

    public function submitChallengeCode(string $browserSessionRef, SensitiveString $code): PortalAuthResult
    {
        $response = $this->post("/v1/spv/sessions/{$browserSessionRef}/challenge", [
            'code' => $code->reveal(),
        ]);

        return $this->parseAuthResult($response, 'challenge');
    }

    public function destroySession(string $browserSessionRef): void
    {
        $response = $this->delete("/v1/spv/sessions/{$browserSessionRef}");

        if ($response->status() !== 204 && $response->status() !== 404) {
            throw SpvWorkerUnavailableException::unexpectedResponse($response->status());
        }
    }

    public function requestSync(string $browserSessionRef): void
    {
        $response = $this->post("/v1/spv/sessions/{$browserSessionRef}/actions/sync");

        $this->rejectIfNotImplemented($response, 'sync');

        if ($response->failed()) {
            throw SpvWorkerUnavailableException::unexpectedResponse($response->status());
        }
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body = []): Response
    {
        return $this->call(fn (): Response => $this->http
            ->timeout($this->timeoutSeconds)
            ->withToken($this->authToken)
            ->post(rtrim($this->baseUrl, '/') . $path, $body));
    }

    private function delete(string $path): Response
    {
        return $this->call(fn (): Response => $this->http
            ->timeout($this->timeoutSeconds)
            ->withToken($this->authToken)
            ->delete(rtrim($this->baseUrl, '/') . $path));
    }

    /** @param callable(): Response $request */
    private function call(callable $request): Response
    {
        if ($this->authToken === '') {
            throw SpvWorkerUnavailableException::authenticationNotConfigured();
        }

        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw SpvWorkerUnavailableException::requestFailed($exception);
        } catch (Throwable $exception) {
            throw SpvWorkerUnavailableException::requestFailed($exception);
        }

        if ($response->status() === 401) {
            throw SpvWorkerUnavailableException::authenticationRejected();
        }

        return $response;
    }

    private function rejectIfNotImplemented(Response $response, string $action): void
    {
        if ($response->status() !== 501) {
            return;
        }

        $decoded = json_decode($response->body(), true);
        $payload = is_array($decoded) ? $decoded : [];

        if (($payload['error'] ?? null) === 'not_implemented') {
            throw SpvPortalAutomationUnavailableException::forAction($action);
        }

        throw SpvWorkerUnavailableException::unexpectedResponse(501);
    }

    private function parseAuthResult(Response $response, string $action): PortalAuthResult
    {
        $this->rejectIfNotImplemented($response, $action);

        if ($response->status() === 404) {
            throw SpvWorkerUnavailableException::unexpectedResponse(404);
        }

        if ($response->failed()) {
            throw SpvWorkerUnavailableException::unexpectedResponse($response->status());
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_string($payload['status'] ?? null)) {
            throw SpvWorkerUnavailableException::malformedResponse();
        }

        $state = match ($payload['status']) {
            'awaiting_challenge' => SpvSessionState::AWAITING_USER_CHALLENGE,
            'authenticated' => SpvSessionState::AUTHENTICATED,
            'failed' => SpvSessionState::FAILED,
            default => throw SpvWorkerUnavailableException::malformedResponse(),
        };

        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : null;

        return new PortalAuthResult($state, $reason);
    }
}
