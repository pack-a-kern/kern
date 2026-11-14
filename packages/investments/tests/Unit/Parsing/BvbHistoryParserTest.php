<?php

declare(strict_types=1);

namespace Kern\Investments\Tests\Unit\Parsing\BvbHistoryParserTest;

use Kern\Investments\Parsing\Bvb\BvbHistoryParser;
use Kern\Investments\Parsing\ParseFailure;

function history(): string
{
    return (string) file_get_contents(__DIR__ . '/../../Fixtures/bvb/history-bond.json');
}

it('reads each trading day’s close from BVB’s real chart prices, as the text BVB wrote', function (): void {
    $closes = BvbHistoryParser::parse(history());

    expect($closes)->toHaveCount(53)
        ->and($closes[0]->day->format('Y-m-d'))->toBe('2026-08-20')
        ->and($closes[0]->close)->toBe('100.5980')
        ->and($closes[52]->day->format('Y-m-d'))->toBe('2026-11-13')
        ->and($closes[52]->close)->toBe('98.0350');
});

it('reads a span with no trading as no prices', function (): void {
    expect(BvbHistoryParser::parse('{"s":"no_data","nextTime":null}'))->toBe([]);
});

it('fails an answer that is not prices, or not a close for each day', function (string $json, string $why): void {
    expect(fn () => BvbHistoryParser::parse($json))->toThrow(ParseFailure::class, $why);
})->with([
    'not JSON' => ['<html>Service unavailable</html>', 'not JSON'],
    'an error' => ['{"s":"error","errmsg":"unknown symbol"}', 'neither prices nor "no_data"'],
    'a day short' => ['{"s":"ok","t":[1787184000],"c":[100.5,101.0]}', 'a close for each day'],
    'a price that is not one' => ['{"s":"ok","t":[1787184000],"c":["n/a"]}', 'bar 1: not a day and a price'],
]);
