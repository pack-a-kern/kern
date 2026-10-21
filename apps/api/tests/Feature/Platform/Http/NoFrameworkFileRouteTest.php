<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Http\NoFrameworkFileRouteTest;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
 * A disk with `serve => true` makes the framework register `GET` and `PUT storage/{path}` outside the
 * application's middleware. No disk here serves itself: every file goes out through a controller with
 * the application's own checks, so the routes must not exist at all.
 */

it('registers no file route of the framework’s own', function (): void {
    $fileRoutes = collect(Router::getRoutes()->getRoutes())
        ->map(static fn (Route $route): string => $route->uri())
        ->filter(static fn (string $uri): bool => str_starts_with($uri, 'storage/'))
        ->values()
        ->all();

    expect($fileRoutes)->toBe([]);
});

it('lets no disk serve itself', function (): void {
    $serving = collect(config()->array('filesystems.disks'))
        ->filter(static fn (mixed $disk): bool => is_array($disk) && ($disk['serve'] ?? false) === true)
        ->keys()
        ->all();

    expect($serving)->toBe([]);
});
