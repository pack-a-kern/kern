<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

/*
 * The page reads a setting, so it needs the schema — this suite is one of the few whose directory
 * `tests/Pest.php` does not already name. It passed anyway wherever a previous run had left the
 * tables behind, and failed on the in-memory database that starts with none, which is the same test
 * telling two different stories depending on what ran before it.
 */
uses(RefreshDatabase::class);

test('the home page renders the home Inertia component', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('home'));
});
