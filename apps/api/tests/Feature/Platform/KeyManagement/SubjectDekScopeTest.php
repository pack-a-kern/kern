<?php

declare(strict_types=1);

use Kern\Platform\KeyManagement\SubjectDekService;

/*
 * `SubjectDekService` holds taxpayers' data keys in clear, once unwrapped. In php-fpm the container
 * ends with the request. A queue worker lives for hours, so the keys must not: the service is
 * registered `scoped`, and Laravel's worker forgets every scoped instance after each job
 * (`QueueServiceProvider`'s reset callback, `forgetScopedInstances()`).
 *
 * Registered any other way — a singleton — the keys of every taxpayer a worker ever touched would
 * stay in its memory until it restarts, and survive a key's rotation or revocation.
 */

it('keeps one key cache for the length of a job', function (): void {
    expect(app(SubjectDekService::class))->toBe(app(SubjectDekService::class));
});

it('forgets every decrypted key when a job ends', function (): void {
    $during = app(SubjectDekService::class);

    // What the queue worker does after every job.
    app()->forgetScopedInstances();

    expect(app(SubjectDekService::class))->not->toBe($during);
});
