<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kern\Platform\Tests\TestCase;

/*
 * Only `Integration/` boots Laravel.
 *
 * Those are the proofs that this package installs into an empty application and works — they need
 * Testbench and a database. `Unit/` is plain PHP over plain classes: no container, no schema, and it
 * runs in milliseconds, which is the whole reason those tests are worth having close to the code.
 */
uses(TestCase::class, RefreshDatabase::class)->in('Integration');

/** A file under this package's `tests/Fixtures`, anchored here rather than counted from the caller. */
function packageFixture(string $path): string
{
    return __DIR__ . '/Fixtures/' . ltrim($path, '/');
}
