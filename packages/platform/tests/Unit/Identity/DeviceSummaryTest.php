<?php

declare(strict_types=1);

use Kern\Platform\Identity\Enums\DeviceKind;
use Kern\Platform\Identity\Support\DeviceSummary;

/**
 * Reading a user agent down to a kind, a browser and a platform.
 *
 * Every case here is a real string, because the only hard part of this is that browsers lie about
 * being each other and the lies are specific. A hand-written example would test the code against the
 * author's idea of a user agent rather than against one.
 */
it('reads a phone', function (): void {
    $summary = DeviceSummary::read(
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 '
        . '(KHTML, like Gecko) Version/26.6.1 Mobile/15E148 Safari/604.1',
    );

    expect($summary->kind)->toBe(DeviceKind::MOBILE)
        ->and($summary->browser)->toBe('Safari')
        // Not macOS: an iPhone's agent says "like Mac OS X", which is true of the kernel and not of
        // anything the owner of the phone would recognise.
        ->and($summary->platform)->toBe('iOS');
});

it('reads a desktop', function (): void {
    $summary = DeviceSummary::read(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/153.0.0.0 Safari/537.36',
    );

    expect($summary->kind)->toBe(DeviceKind::DESKTOP)
        // Chrome claims Safari. Checking Safari first would make every Chrome in the world a Safari.
        ->and($summary->browser)->toBe('Chrome')
        ->and($summary->platform)->toBe('Windows');
});

/*
 * The family that claims to be the family before it.
 *
 * Edge sends `Edg/` and `Chrome/` and `Safari/`; Opera sends `OPR/` and all of those. Any of these
 * read in the wrong order comes back as Chrome, and a person looking for the browser they actually
 * use would not find it.
 */
it('tells the Chromium family apart, each of which claims to be the last', function (string $agent, string $browser): void {
    expect(DeviceSummary::read($agent)->browser)->toBe($browser);
})->with([
    'Edge' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0',
        'Edge',
    ],
    'Opera' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/153.0.0.0 Safari/537.36 OPR/139.0.0.0',
        'Opera',
    ],
    'Samsung Internet' => [
        'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36',
        'Samsung Internet',
    ],
    'Firefox' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0',
        'Firefox',
    ],
]);

/*
 * A tablet is its phone minus one word.
 *
 * Android drops "Mobile" when it is not one, and that absence is the only thing separating the two.
 * An iPad names itself, having long since stopped admitting to being an iPhone.
 */
it('separates a tablet from a phone', function (string $agent, DeviceKind $kind): void {
    expect(DeviceSummary::read($agent)->kind)->toBe($kind);
})->with([
    'android phone' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/153.0.0.0 Mobile Safari/537.36',
        DeviceKind::MOBILE,
    ],
    'android tablet' => [
        'Mozilla/5.0 (Linux; Android 14; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/153.0.0.0 Safari/537.36',
        DeviceKind::TABLET,
    ],
    'ipad' => [
        'Mozilla/5.0 (iPad; CPU OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
        . 'Version/26.6 Safari/604.1',
        DeviceKind::TABLET,
    ],
]);

it('calls out something automated rather than drawing it a laptop', function (string $agent): void {
    expect(DeviceSummary::read($agent)->kind)->toBe(DeviceKind::ROBOT);
})->with([
    'headless chrome' => [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'HeadlessChrome/153.0.8010.12 Safari/537.36',
    ],
    'curl' => ['curl/8.5.0'],
    'a crawler claiming to be a browser' => [
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    ],
]);

/*
 * What an unread agent must do.
 *
 * Not guess. The row keeps the raw string beside this reading, so nulls cost the reader nothing
 * while a wrong confident answer would cost them the point of the screen.
 */
it('admits to knowing nothing rather than guessing', function (?string $agent): void {
    $summary = DeviceSummary::read($agent);

    expect($summary->kind)->toBe(DeviceKind::UNKNOWN)
        ->and($summary->browser)->toBeNull()
        ->and($summary->platform)->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
    'something nobody has seen' => ['Kern-Future-Client/4.0'],
]);
