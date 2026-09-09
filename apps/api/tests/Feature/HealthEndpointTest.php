<?php

declare(strict_types=1);

test('the health endpoint reports the application is up', function (): void {
    $this->get('/up')->assertOk();
});
