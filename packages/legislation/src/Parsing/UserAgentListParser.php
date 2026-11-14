<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing;

use Carbon\CarbonImmutable;
use Kern\Legislation\DataTransferObjects\KnownUserAgent;
use Kern\Legislation\DataTransferObjects\UserAgentList;
use Kern\Legislation\Enums\UserAgentBrowser;
use Kern\Legislation\Enums\UserAgentSystem;
use Kern\Legislation\Parsing\Exceptions\UnreadableUserAgentListException;
use Kern\Platform\BrowserWorker\BrowserEngine;
use Kern\Platform\Settings\Enums\SettingType;

/**
 * microlink.io's list of browsers' `User-Agent`s (`{"updatedAt": <ms>, "user": [...]}`) — its `user` names
 * only; `ai` and `crawler` are bots' names, which no browser here goes out as.
 *
 * Each name is read for its system, its browser and that browser's major version, and from those its
 * engine: every browser on iOS is WebKit, whatever it calls itself. A name this cannot place, or one a
 * header could not carry, is counted and left out rather than guessed at.
 */
final class UserAgentListParser
{
    /** The longest name kept — the column's width; a real one is under two hundred. */
    public const int MAX_LENGTH = 512;

    /** Most specific first: an iPhone's name says "like Mac OS X", Android's says Linux. */
    private const array SYSTEMS = [
        '/iPhone|iPad|iPod/' => UserAgentSystem::IOS,
        '/Android/' => UserAgentSystem::ANDROID,
        '/CrOS/' => UserAgentSystem::CHROMEOS,
        '/Windows/' => UserAgentSystem::WINDOWS,
        '/Macintosh/' => UserAgentSystem::MACOS,
        '/X11|Linux/' => UserAgentSystem::LINUX,
    ];

    /** Most specific first: Edge and Opera also say Chrome, Chrome also says Safari. */
    private const array BROWSERS = [
        '#(?:Edg|EdgiOS|EdgA)/(\d+)#' => UserAgentBrowser::EDGE,
        '#OPR/(\d+)#' => UserAgentBrowser::OPERA,
        '#(?:Firefox|FxiOS)/(\d+)#' => UserAgentBrowser::FIREFOX,
        '#(?:Chrome|CriOS)/(\d+)#' => UserAgentBrowser::CHROME,
        '#Version/(\d+)[^ ]* (?:Mobile/\S+ )?Safari/#' => UserAgentBrowser::SAFARI,
    ];

    public function parse(string $json): UserAgentList
    {
        $list = json_decode($json, true);

        if (! is_array($list) || ! is_int($list['updatedAt'] ?? null) || ! is_array($list['user'] ?? null)) {
            throw UnreadableUserAgentListException::notTheList();
        }

        $agents = array_values(array_filter(array_map(
            fn (string $name): ?KnownUserAgent => $this->read($name),
            array_unique(array_filter($list['user'], 'is_string')),
        )));

        if ($agents === []) {
            throw UnreadableUserAgentListException::empty();
        }

        // Left out: what was not a name, a name given twice, and one this could not place.
        // To the second: the date is kept to the second, and a list read again must compare equal to it.
        return new UserAgentList(CarbonImmutable::createFromTimestampMsUTC($list['updatedAt'])->startOfSecond(), $agents, count($list['user']) - count($agents));
    }

    private function read(string $name): ?KnownUserAgent
    {
        if (strlen($name) > self::MAX_LENGTH || ! SettingType::isHeaderValue($name)) {
            return null;
        }

        $system = $this->system($name);

        if ($system === null) {
            return null;
        }

        foreach (self::BROWSERS as $pattern => $browser) {
            if (preg_match($pattern, $name, $match) === 1) {
                return new KnownUserAgent($name, $this->engine($system, $browser), $system, $browser, (int) $match[1]);
            }
        }

        return null;
    }

    private function engine(UserAgentSystem $system, UserAgentBrowser $browser): BrowserEngine
    {
        return match (true) {
            $system === UserAgentSystem::IOS, $browser === UserAgentBrowser::SAFARI => BrowserEngine::WEBKIT,
            $browser === UserAgentBrowser::FIREFOX => BrowserEngine::FIREFOX,
            default => BrowserEngine::CHROMIUM,
        };
    }

    private function system(string $name): ?UserAgentSystem
    {
        foreach (self::SYSTEMS as $pattern => $value) {
            if (preg_match($pattern, $name) === 1) {
                return $value;
            }
        }

        return null;
    }
}
