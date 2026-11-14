<?php

declare(strict_types=1);

/*
 * No cross-origin reader: the API answers another site's browser with no CORS header at all — not on a
 * preflight, and not on the request itself — so a page elsewhere can never read what it sends back.
 */

it('answers a preflight from another origin with no CORS header', function (): void {
    $response = $this->call('OPTIONS', '/api/v1/me', server: [
        'HTTP_ORIGIN' => 'https://elsewhere.example.test',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Methods'))->toBeFalse();
});

it('answers a request from another origin with no CORS header', function (): void {
    $response = $this->getJson('/api/v1/me', ['Origin' => 'https://elsewhere.example.test']);

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});
