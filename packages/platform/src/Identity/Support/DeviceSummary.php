<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Support;

use Kern\Platform\Identity\Enums\DeviceKind;

/**
 * A user agent string, read down to the three things a person checking their devices actually uses.
 *
 * ## Why this is hand-written rather than a package
 *
 * `docs/DEPENDENCY-POLICY.md` §4 asks for the standard library before a third-party package, and the
 * question here is small: a kind, a browser, a platform. The libraries that do this properly carry
 * thousands of regular expressions to tell a Xiaomi Redmi from a Redmi Note, which is a problem this
 * screen does not have — nobody is reading "do I recognise this?" and needing the model number.
 *
 * The cost of being hand-written is that it will not know next year's browser. That is the right
 * failure: an unrecognised agent returns nulls and the row says so, rather than claiming something
 * wrong. The full string is returned beside this, so nothing is lost by the reading being partial.
 *
 * ## It names the parts and does not write the sentence
 *
 * "Chrome on Windows" is English, and `BLUEPRINT.md` §19.1 keeps user-facing words out of code. The
 * browser and the platform come back as their own fields and the screen joins them with a phrase
 * from its own dictionary.
 *
 * ## Order matters, and every rule here is a lie some agent tells
 *
 * Every browser claims to be the ones before it: Edge says Chrome, Chrome says Safari, Safari says
 * Gecko. So the checks run most-particular first and stop at the first that holds. The pattern of
 * each is "the marker that only this one has".
 */
final readonly class DeviceSummary
{
    private function __construct(
        public DeviceKind $kind,
        public ?string $browser,
        public ?string $platform,
    ) {
    }

    public static function read(?string $userAgent): self
    {
        $agent = trim($userAgent ?? '');

        if ($agent === '') {
            return new self(DeviceKind::UNKNOWN, null, null);
        }

        return new self(self::kind($agent), self::browser($agent), self::platform($agent));
    }

    private static function kind(string $agent): DeviceKind
    {
        // First, because a crawler is free to claim any of the rest and several do.
        if (self::matches($agent, ['bot', 'crawler', 'spider', 'headless', 'curl/', 'wget/', 'python-requests'])) {
            return DeviceKind::ROBOT;
        }

        // An iPad and an Android tablet both read as their phone but for one marker each: the iPad
        // names itself, and Android drops the word "Mobile" when it is not one.
        if (str_contains($agent, 'iPad') || (str_contains($agent, 'Android') && ! str_contains($agent, 'Mobile'))) {
            return DeviceKind::TABLET;
        }

        if (self::matches($agent, ['iPhone', 'iPod', 'Android', 'Mobile', 'Windows Phone'])) {
            return DeviceKind::MOBILE;
        }

        if (self::matches($agent, ['Windows', 'Macintosh', 'Mac OS X', 'CrOS', 'Linux', 'X11'])) {
            return DeviceKind::DESKTOP;
        }

        return DeviceKind::UNKNOWN;
    }

    private static function browser(string $agent): ?string
    {
        /*
         * Most particular first. Edge carries `Edg/` *and* `Chrome/`; Opera carries `OPR/` and both
         * of those; Chrome carries `Safari/`. Reversing any pair here mislabels the whole family.
         */
        $markers = [
            'Edg' => 'Edge',
            'OPR' => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'HeadlessChrome' => 'Headless Chrome',
            'Firefox' => 'Firefox',
            'Chrome' => 'Chrome',
            'Safari' => 'Safari',
        ];

        foreach ($markers as $marker => $name) {
            if (str_contains($agent, $marker)) {
                return $name;
            }
        }

        return null;
    }

    private static function platform(string $agent): ?string
    {
        /*
         * iPadOS before iOS, and both before macOS: an iPad's agent says `Mac OS X` as well, which is
         * Apple telling the truth about the kernel and nothing a reader wants to be told.
         */
        $markers = [
            'iPad' => 'iPadOS',
            'iPhone' => 'iOS',
            'iPod' => 'iOS',
            'Android' => 'Android',
            'Windows' => 'Windows',
            'CrOS' => 'ChromeOS',
            'Mac OS X' => 'macOS',
            'Macintosh' => 'macOS',
            'Linux' => 'Linux',
        ];

        foreach ($markers as $marker => $name) {
            if (str_contains($agent, $marker)) {
                return $name;
            }
        }

        return null;
    }

    /** @param list<string> $needles */
    private static function matches(string $agent, array $needles): bool
    {
        $haystack = strtolower($agent);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
