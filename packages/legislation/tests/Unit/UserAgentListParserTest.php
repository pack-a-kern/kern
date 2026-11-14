<?php

declare(strict_types=1);

use Kern\Legislation\DataTransferObjects\KnownUserAgent;
use Kern\Legislation\Parsing\Exceptions\UnreadableUserAgentListException;
use Kern\Legislation\Parsing\UserAgentListParser;

/** A list in the published shape, its names the most used browsers' as such lists give them. */
function userAgentList(): string
{
    return (string) file_get_contents(__DIR__ . '/../Fixtures/UserAgents/list.json');
}

it('reads each name for its system, browser, version and the engine behind it', function (): void {
    $list = (new UserAgentListParser())->parse(userAgentList());
    $read = array_map(static fn (KnownUserAgent $agent): string => "{$agent->system->value} {$agent->browser->value} {$agent->version} {$agent->engine->value}", $list->agents);

    expect($read)->toBe([
        'windows chrome 156 chromium',
        'windows edge 156 chromium',
        'windows opera 120 chromium',
        'windows firefox 150 firefox',
        'macos safari 19 webkit',
        'linux chrome 156 chromium',
        'android chrome 146 chromium',
        'ios safari 26 webkit',
        // Chrome on iOS is WebKit: Apple allows no other engine there.
        'ios chrome 156 webkit',
        'chromeos chrome 156 chromium',
    ])
        ->and($list->publishedAt->toIso8601String())->toBe('2026-11-09T03:43:25+00:00')
        // A name given twice, one nothing here can place, and one that is not a name.
        ->and($list->skipped)->toBe(3);
});

it('refuses what is not the list, and a list with nothing it can place', function (string $json): void {
    expect(fn () => (new UserAgentListParser())->parse($json))->toThrow(UnreadableUserAgentListException::class);
})->with([
    'not JSON' => '<html>',
    'no date' => '{"user": []}',
    'nothing placed' => '{"updatedAt": 1794195805215, "user": ["Example/1.0"]}',
]);

it('leaves out a name a header could not carry', function (): void {
    $list = (new UserAgentListParser())->parse(json_encode(['updatedAt' => 1794195805215, 'user' => [
        "Mozilla/5.0 (X11; Linux x86_64) Chrome/156.0.0.0 Safari/537.36\r\nX-Injected: 1",
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36',
    ]], JSON_THROW_ON_ERROR));

    expect($list->agents)->toHaveCount(1)->and($list->skipped)->toBe(1);
});
