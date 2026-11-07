<?php

declare(strict_types=1);

namespace Kern\Platform\BrowserWorker;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Kern\Platform\BrowserWorker\Exceptions\BrowserActionNotBuiltException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserPageRefusedException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserRunFailedException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserSessionGoneException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerAtCapacityException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerUnavailableException;
use Kern\Platform\Http\StackService;
use Throwable;

/**
 * The one way this installation talks to `apps/browser-worker`.
 *
 * The worker knows no website: every caller sends its own playbook, and this client knows none either.
 * What it knows is the worker's API and what each of its answers means, said once, so the SPV sign-in
 * and the legislation portal read a full worker, a failed run and an unreachable one the same way.
 *
 * ## Values, not only secrets
 *
 * The worker substitutes `{{ secret.name }}` from the request's `secrets`, and redacts each of them from
 * anything it says afterwards. A caller passes every value it fills in that way — a password and a search
 * term alike: one more redacted string costs nothing, and a second mechanism for "values that are not
 * secret" would be a second thing to get right.
 *
 * Read from configuration on every call rather than once: the address and the time limit are settings,
 * and a queue worker that has been running for a day should use today's.
 */
final readonly class BrowserWorkerClient
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->worker()->isConfigured();
    }

    /**
     * A browser kept open for the sequences that follow — the pause a sign-in needs.
     *
     * @param  array<string, mixed>  $playbook
     * @return array<string, mixed>
     */
    public function openSession(array $playbook): array
    {
        return $this->decode($this->send('post', '/v1/sessions', ['playbook' => $playbook]));
    }

    /**
     * @param  array<string, mixed>  $playbook
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public function runInSession(string $sessionId, array $playbook, string $sequence, array $values): array
    {
        $response = $this->send('post', '/v1/sessions/' . rawurlencode($sessionId) . '/run', [
            'playbook' => $playbook,
            'sequence' => $sequence,
            'secrets' => $values,
        ]);

        // Here rather than in `decode()`: the same status means something else on the way out, where a
        // 404 from closing a session is a browser already gone.
        if ($response->status() === 404) {
            throw BrowserSessionGoneException::create();
        }

        return $this->decode($response);
    }

    public function closeSession(string $sessionId): void
    {
        $this->decode($this->send('delete', '/v1/sessions/' . rawurlencode($sessionId), []));
    }

    /**
     * One browser for one sequence, closed on the way out.
     *
     * @param  array<string, mixed>  $playbook
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    public function run(array $playbook, string $sequence, array $values): array
    {
        return $this->decode($this->send('post', '/v1/run', ['playbook' => $playbook, 'sequence' => $sequence, 'secrets' => $values]));
    }

    /**
     * The page a sequence's `source` step kept, as its server sent it.
     *
     * The body is held to `$maxBytes` here as well as by the worker: the worker's ceiling is its own
     * deployment's, and this caller's limit is the one it promised whoever reads the page.
     *
     * @param  array<string, mixed>  $playbook
     * @param  array<string, string>  $values
     */
    public function page(array $playbook, string $sequence, array $values, int $maxBytes): BrowserPage
    {
        $response = $this->send('post', '/v1/source', ['playbook' => $playbook, 'sequence' => $sequence, 'secrets' => $values]);

        if ($response->status() === 413) {
            throw BrowserPageRefusedException::tooLarge($maxBytes);
        }

        if ($response->status() === 502 && $response->json('error') === 'source_status') {
            $status = $response->json('status');

            throw BrowserPageRefusedException::status(is_int($status) ? $status : 0);
        }

        if (! $response->successful()) {
            $this->decode($response);
        }

        $declared = $response->header('Content-Length');

        if ((ctype_digit($declared) && (int) $declared > $maxBytes) || strlen($response->body()) > $maxBytes) {
            throw BrowserPageRefusedException::tooLarge($maxBytes);
        }

        $url = $response->header('X-Source-Url');

        if ($url === '') {
            throw BrowserWorkerUnavailableException::malformedResponse();
        }

        $type = $response->header('Content-Type');

        return new BrowserPage($response->body(), $type === '' ? null : $type, $url);
    }

    private function worker(): StackService
    {
        return StackService::browserWorker($this->config);
    }

    /**
     * The worker's answer, undecoded — each caller reads its own statuses first.
     *
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $path, array $body): Response
    {
        $worker = $this->worker();

        if (! $worker->isConfigured()) {
            throw BrowserWorkerUnavailableException::notConfigured();
        }

        // The token is sent only when there is one. The worker decides whether it wants a token; a
        // deployment that trusts the network it is on configures neither side, and this must not refuse
        // on its behalf.
        $request = $worker->request($this->http, $this->config->integer('browser_worker.timeout_seconds'))->asJson();

        try {
            return $method === 'delete'
                ? $request->delete($worker->url($path))
                : $request->post($worker->url($path), $body);
        } catch (Throwable $exception) {
            throw BrowserWorkerUnavailableException::requestFailed($exception);
        }
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->status() === 501) {
            throw BrowserActionNotBuiltException::create();
        }

        // A run the worker attempted and could not finish, which it explains. Not the worker being
        // unreachable — reported as such until it cost an evening, because only the status was read.
        if ($response->status() === 502) {
            $message = $response->json('message');

            throw BrowserRunFailedException::because(is_string($message) ? $message : '');
        }

        if ($response->status() === 503 && $response->json('error') === 'at_capacity') {
            throw BrowserWorkerAtCapacityException::create();
        }

        if ($response->failed()) {
            throw BrowserWorkerUnavailableException::unexpectedResponse($response->status());
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw BrowserWorkerUnavailableException::malformedResponse();
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
