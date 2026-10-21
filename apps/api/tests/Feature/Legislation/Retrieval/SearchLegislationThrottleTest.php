<?php

declare(strict_types=1);

/**
 * Searching legislation, at most so often per account.
 *
 * Each search embeds the query on this server's CPU. A refused query counts like any other — the
 * throttle stands in front of the controller — which is what lets this ask without running a model:
 * a one-letter query is turned away by validation, after it has been counted.
 */
it('limits how often one account may search, and only that account', function (): void {
    config(['legislation.rate_limits.search_per_user.max_attempts' => 2]);

    $reader = ordinaryUser();

    $this->actingAs($reader)->getJson('/api/v1/legislation/search?q=x')->assertStatus(422);
    $this->actingAs($reader)->getJson('/api/v1/legislation/search?q=x')->assertStatus(422);
    $this->actingAs($reader)->getJson('/api/v1/legislation/search?q=x')->assertStatus(429);

    $this->actingAs(ordinaryUser())->getJson('/api/v1/legislation/search?q=x')->assertStatus(422);
});
