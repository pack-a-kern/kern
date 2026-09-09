<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Spv\BroadcastPayloadContainsNoPiiTest;

use App\Domain\Spv\Enums\SpvSyncRunStatus;
use App\Domain\Spv\Events\SpvAuthChallengeRequired;
use App\Domain\Spv\Events\SpvAuthenticated;
use App\Domain\Spv\Events\SpvSyncCompleted;
use App\Domain\Spv\Events\SpvSyncStarted;
use ReflectionClass;
use ReflectionProperty;

/**
 * `BLUEPRINT.md` §16 — broadcast events carry status and progress only, never an SPV username,
 * password, challenge code, or message title. Mirrors `tests/Unit/Domain/Documents/
 * BroadcastPayloadContainsNoPiiTest.php`'s exact-key-set assertion style: a future edit that adds
 * a field must deliberately update this test, not silently smuggle something sensitive past it.
 */
it('SpvAuthChallengeRequired broadcasts only the session id', function (): void {
    $event = new SpvAuthChallengeRequired('session-1', 'subject-1');

    expect($event->broadcastWith())->toBe(['session_id' => 'session-1']);
});

it('SpvAuthenticated broadcasts only the session id', function (): void {
    $event = new SpvAuthenticated('session-1', 'subject-1');

    expect($event->broadcastWith())->toBe(['session_id' => 'session-1']);
});

it('SpvSyncStarted broadcasts only the sync run id and progress', function (): void {
    $event = new SpvSyncStarted('run-1', 'subject-1');

    expect($event->broadcastWith())->toBe(['sync_run_id' => 'run-1', 'progress' => 0]);
});

it('SpvSyncCompleted broadcasts only ids, status, message count, and progress — never a message title', function (): void {
    $event = new SpvSyncCompleted('run-1', 'subject-1', SpvSyncRunStatus::SUCCEEDED, 3);

    expect($event->broadcastWith())->toBe([
        'sync_run_id' => 'run-1',
        'status' => 'SUCCEEDED',
        'message_count' => 3,
        'progress' => 100,
    ]);
});

it('no Spv broadcast event class defines a public property carrying a credential, code, or message content', function (): void {
    $classes = [SpvAuthChallengeRequired::class, SpvAuthenticated::class, SpvSyncStarted::class, SpvSyncCompleted::class];
    $forbidden = ['password', 'code', 'username', 'title', 'text', 'content'];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = mb_strtolower($property->getName());
            foreach ($forbidden as $needle) {
                expect($name)->not->toContain($needle);
            }
        }
    }
});
