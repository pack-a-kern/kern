<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Kern\Platform\Config\RequiredConfig;

/*
 * A setting the code needs is read one way: present and of the right shape, or refused with its
 * name. Never an empty string carried on, and never a number from the code standing in for one
 * nobody configured.
 */

/** @param  array<string, mixed>  $values */
function requiredConfig(array $values): RequiredConfig
{
    return new RequiredConfig(new Repository($values));
}

it('returns a value that is set', function (): void {
    $config = requiredConfig(['a' => ['url' => 'https://processing:8443', 'timeout' => 30]]);

    expect($config->string('a.url'))->toBe('https://processing:8443')
        ->and($config->optionalString('a.url'))->toBe('https://processing:8443')
        ->and($config->positiveInt('a.timeout'))->toBe(30);
});

it('refuses a missing or empty string, naming the setting', function (mixed $value): void {
    expect(fn () => requiredConfig(['a' => ['url' => $value]])->string('a.url'))
        ->toThrow(RuntimeException::class, '"a.url"');
})->with(['missing' => [null], 'empty' => [''], 'a number' => [8443]]);

it('answers null for an optional string that is missing or empty', function (mixed $value): void {
    expect(requiredConfig(['a' => ['token' => $value]])->optionalString('a.token'))->toBeNull();
})->with(['missing' => [null], 'empty' => ['']]);

it('refuses a number that is missing, zero, negative or text, naming the setting', function (mixed $value): void {
    expect(fn () => requiredConfig(['a' => ['timeout' => $value]])->positiveInt('a.timeout'))
        ->toThrow(RuntimeException::class, '"a.timeout"');
})->with(['missing' => [null], 'zero' => [0], 'negative' => [-5], 'text' => ['30']]);

it('reads the probes\' ages from config, not from numbers in the code', function (): void {
    // The three settings that used to fall back to 180, 4 and 60 written beside the call.
    foreach (['operations.scheduler.heartbeat_max_age_seconds', 'operations.freshness.fx_max_age_days', 'operations.freshness.document_stuck_after_minutes'] as $key) {
        expect(app(RequiredConfig::class)->positiveInt($key))->toBeGreaterThan(0);
    }
});
