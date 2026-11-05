<?php

declare(strict_types=1);

namespace Kern\Notifications\Testing;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A push service for tests: answers each request with the next status it was given (201 once they run
 * out), and keeps every request, so a test can read what would have gone over the wire.
 *
 * `new PushSender(new FakePushService([201, 410]))`, or bound in the container in its place.
 */
final class FakePushService implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $received = [];

    /** @param list<int> $statuses */
    public function __construct(private array $statuses = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->received[] = $request;

        return new Response(array_shift($this->statuses) ?? 201);
    }
}
