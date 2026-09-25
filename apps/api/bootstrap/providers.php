<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

/*
 * Every module's provider is discovered from its package now. What is left is this application's own
 * wiring — see `kern/platform`, `kern/audit`, `kern/documents`, `kern/legislation` and
 * `kern/tax-romania`, each of which registers itself.
 */
return [
    AppServiceProvider::class,
];
