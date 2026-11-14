<?php

declare(strict_types=1);

namespace Kern\Legislation\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Kern\Legislation\DataTransferObjects\UserAgentListReading;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
use Kern\Legislation\Models\UserAgent;
use Kern\Legislation\Parsing\UserAgentListParser;
use RuntimeException;

/**
 * Reads the published list of browsers' `User-Agent`s and keeps it whole, replacing the one before: the
 * list is the most used names this week, so last week's that left it are not offered any more. A list
 * already read — its date no later than the one kept — changes nothing.
 *
 * The name the Browser card has saved is a setting, not a row here, so a name leaving the list never
 * changes what this installation sends.
 */
final readonly class UserAgentListImport
{
    public const string SOURCE = 'USER_AGENTS';

    public function __construct(
        private ConfigRepository $config,
        private AllowlistedHttpFetcher $fetcher,
        private UserAgentListParser $parser,
    ) {
    }

    public function __invoke(): UserAgentListReading
    {
        $url = $this->config->get('sources.entries.' . self::SOURCE . '.endpoints.list');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('config/sources.php must name the User-Agent list (SOURCES_USER_AGENTS_URL).');
        }

        $list = $this->parser->parse($this->fetcher->fetch(self::SOURCE, $url)->body);
        $kept = UserAgent::query()->first()?->published_at;

        // One list at a time is kept, so any row carries its date.
        if ($kept !== null && $list->publishedAt->lessThanOrEqualTo($kept)) {
            return new UserAgentListReading($list->publishedAt, UserAgent::query()->count(), $list->skipped, false);
        }

        DB::transaction(static function () use ($list): void {
            UserAgent::query()->delete();

            foreach ($list->agents as $agent) {
                UserAgent::query()->create([
                    'user_agent' => $agent->userAgent,
                    'engine' => $agent->engine,
                    'system' => $agent->system,
                    'browser' => $agent->browser,
                    'version' => $agent->version,
                    'published_at' => $list->publishedAt,
                ]);
            }
        });

        return new UserAgentListReading($list->publishedAt, count($list->agents), $list->skipped, true);
    }
}
