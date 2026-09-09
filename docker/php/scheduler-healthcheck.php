<?php

declare(strict_types=1);

/**
 * Healthcheck for the `scheduler` container.
 *
 * Asserts that the schedule *ran recently*, not merely that a process exists. `schedule:work` can
 * be alive and wedged — a stuck subprocess, a lock never released — and a process check would call
 * that healthy right up until someone noticed the exchange rates had stopped arriving.
 *
 * The previous check shelled out to `pgrep`, which is not installed in this image, so it failed
 * from its first tick onward regardless of the scheduler's actual state: a permanently red signal,
 * in which a genuine failure looked exactly like the healthy case. Written in PHP because PHP is
 * the one interpreter this image is guaranteed to have.
 *
 * Both values come from the same environment variables `config/operations.php` reads, so this
 * check, the schedule entry that writes the file, and the operations screen that reports its age
 * can never disagree about which file they mean or when it counts as stale. The defaults below
 * repeat that config's defaults deliberately: this script runs before the framework boots and has
 * no container to ask.
 *
 * Exit 0 = healthy; any other exit = unhealthy.
 */
$pathFromEnv = getenv('OPERATIONS_HEARTBEAT_PATH');
$path = is_string($pathFromEnv) && $pathFromEnv !== '' ? $pathFromEnv : '/tmp/scheduler-heartbeat';

// Generous against a one-minute cadence: a single missed tick under load is not a fault, and a
// check that flaps teaches people to ignore it. Three consecutive misses is a real stall.
$maxAgeFromEnv = getenv('OPERATIONS_HEARTBEAT_MAX_AGE_SECONDS');
$staleAfterSeconds = is_string($maxAgeFromEnv) && ctype_digit($maxAgeFromEnv) ? (int) $maxAgeFromEnv : 180;

if (! is_file($path)) {
    // Also the state during the first minute after start, which is why the compose healthcheck
    // gives this a `start_period` before failures begin to count.
    fwrite(STDERR, "scheduler: no heartbeat yet at {$path}\n");

    exit(1);
}

clearstatcache(true, $path);
$age = time() - (int) filemtime($path);

if ($age > $staleAfterSeconds) {
    fwrite(STDERR, "scheduler: heartbeat is {$age}s old (limit {$staleAfterSeconds}s) — the schedule has stopped\n");

    exit(1);
}

exit(0);
