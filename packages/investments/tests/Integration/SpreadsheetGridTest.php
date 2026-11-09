<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Integration\SpreadsheetGridTest;

use Illuminate\Support\Facades\Http;
use Kern\Investments\Processing\SpreadsheetGrid;
use Kern\Investments\Processing\SpreadsheetUnreadable;

it('sends the workbook to the processing service, authenticated, and hands back its rows', function (): void {
    Http::fake(['https://processing.test/v1/spreadsheets/grid' => Http::response(['sheet' => 'S', 'sheets' => ['S'], 'rows' => [['', 'Cod emisiune'], []]])]);

    expect(app(SpreadsheetGrid::class)->rows('PK-bytes'))->toBe([['', 'Cod emisiune'], []]);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token') && $request->isMultipart());
});

it('reads nothing from an answer that is not a grid', function (mixed $body, int $status, string $why): void {
    Http::fake(['https://processing.test/*' => Http::response($body, $status)]);

    expect(fn () => app(SpreadsheetGrid::class)->rows('PK-bytes'))->toThrow(SpreadsheetUnreadable::class, $why);
})->with([
    'a refusal' => [['error' => 'UNSUPPORTED_FORMAT', 'message' => 'x'], 422, 'UNSUPPORTED_FORMAT'],
    'a failure with no body' => ['', 502, 'HTTP 502'],
    'no rows' => [['sheet' => 'S'], 200, 'no rows'],
    'a row of numbers' => [['rows' => [[1, 2]]], 200, 'not a list of text'],
    'rows keyed by name' => [['rows' => ['a' => ['x']]], 200, 'no rows'],
]);

it('sends nothing while the service is not configured', function (): void {
    Http::fake();
    config(['services.processing.auth_token' => '']);

    expect(fn () => app(SpreadsheetGrid::class)->rows('PK-bytes'))->toThrow(SpreadsheetUnreadable::class, 'INTERNAL_SERVICE_AUTH_TOKEN');
    Http::assertNothingSent();
});
