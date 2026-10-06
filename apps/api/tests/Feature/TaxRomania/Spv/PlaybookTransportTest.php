<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Spv\PlaybookTransportTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Kern\TaxRomania\Spv\Contracts\SpvBrowserSessionClient;
use Kern\TaxRomania\Spv\Enums\SpvSessionState;
use Kern\TaxRomania\Spv\Transport\PlaybookBrowserSessionClient;
use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;

/**
 * What this application sends the browser worker, and what it makes of the answer.
 *
 * The worker knows no website; everything about ANAF travels with the request. These cover the half
 * of that contract this side owns — that the playbook goes with every call, that a secret goes only
 * where the playbook asked for one, and that an outcome the playbook named becomes a state the
 * actions understand.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);

    config()->set('spv.browser_worker.base_url', 'http://browser-worker.test');
    config()->set('spv.browser_worker.auth_token', 'test-token');
});

it('is the transport the application resolves', function (): void {
    // The binding is the whole migration: everything above it asks for the contract, not a class.
    // Asserted by name: the container's return type is the contract, which is exactly what this
    // test is checking the implementation of.
    $client = app(SpvBrowserSessionClient::class);

    expect($client::class)->toBe(PlaybookBrowserSessionClient::class);
});

it('sends the playbook with the request rather than assuming the worker knows the site', function (): void {
    Http::fake([
        '*/v1/sessions' => Http::response(['session_id' => 'abc', 'expires_at' => '2026-10-06T10:00:00+00:00']),
    ]);

    app(SpvBrowserSessionClient::class)->createSession();

    Http::assertSent(function (Request $request): bool {
        $playbook = $request->data()['playbook'] ?? null;

        if (! is_array($playbook) || ! is_array($playbook['sequences'] ?? null)) {
            return false;
        }

        return $playbook['name'] === 'anaf-spv'
            // Destinations are the playbook's, checked by the worker before every navigation.
            && $playbook['allow'] === ['anaf.ro']
            && array_keys($playbook['sequences']) === ['signIn', 'challenge', 'inbox'];
    });
});

it('sends a password only as the secret the playbook named', function (): void {
    Http::fake(['*/run' => Http::response(['outcome' => 'awaiting_challenge', 'data' => []])]);

    app(SpvBrowserSessionClient::class)
        ->submitCredentials('abc', 'contribuabil', SensitiveString::wrap('hunter2'));

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        // The playbook writes `{{ secret.password }}` and never the value; the value travels beside
        // it, for this one request, and is held nowhere on either side.
        return $body['sequence'] === 'signIn'
            && $body['secrets'] === ['username' => 'contribuabil', 'password' => 'hunter2']
            && ! str_contains(json_encode($body['playbook']) ?: '', 'hunter2');
    });
});

// The playbook names its own outcomes, so this mapping lives here rather than in the worker — which
// is what lets another portal name them differently and need no change there. One test each: a
// second `Http::fake` adds a stub rather than replacing the first.
it('reads a challenge the portal is waiting on', function (): void {
    Http::fake(['*/run' => Http::response(['outcome' => 'awaiting_challenge', 'data' => []])]);

    expect(app(SpvBrowserSessionClient::class)->submitChallengeCode('abc', SensitiveString::wrap('123456'))->state)
        ->toBe(SpvSessionState::AWAITING_USER_CHALLENGE);
});

it('reads a sign-in the portal accepted', function (): void {
    Http::fake(['*/run' => Http::response(['outcome' => 'authenticated', 'data' => []])]);

    expect(app(SpvBrowserSessionClient::class)->submitChallengeCode('abc', SensitiveString::wrap('123456'))->state)
        ->toBe(SpvSessionState::AUTHENTICATED);
});

it('carries back what the portal said when it refused', function (): void {
    Http::fake(['*/run' => Http::response(['outcome' => 'failed', 'message' => 'Parolă greșită', 'data' => []])]);

    $result = app(SpvBrowserSessionClient::class)->submitCredentials('abc', 'x', SensitiveString::wrap('y'));

    expect($result->state)->toBe(SpvSessionState::FAILED)
        ->and($result->reason)->toBe('Parolă greșită');
});

it('calls without a token when none is configured, rather than refusing on the worker’s behalf', function (): void {
    // The worker decides whether it wants one: a deployment that trusts the network it is on
    // configures neither side. The client this replaces refused instead, which is why a missing
    // token surfaced as a 500 rather than a call the worker would have accepted.
    config()->set('spv.browser_worker.auth_token', '');

    Http::fake([
        '*/v1/sessions' => Http::response(['session_id' => 'abc', 'expires_at' => '2026-10-06T10:00:00+00:00']),
    ]);

    app(SpvBrowserSessionClient::class)->createSession();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});
