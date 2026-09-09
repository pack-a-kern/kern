<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

test('the home page renders the home Inertia component', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('home'));
});
