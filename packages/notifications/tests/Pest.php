<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kern\Notifications\Tests\TestCase;

/*
 * Only `Integration/` boots Laravel. `Unit/` is plain PHP over plain classes, and runs in milliseconds.
 */
uses(TestCase::class, RefreshDatabase::class)->in('Integration');
