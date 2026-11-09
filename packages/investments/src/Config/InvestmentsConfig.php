<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Investments\Enums\Programme;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\DataTransferObjects\SourceDefinition;
use Kern\Legislation\Watches\LawWatchPattern;
use RuntimeException;

/**
 * This package's configuration, read once and validated (ADR 0025 §4). The only class that reads
 * `config('investments.…')`: a value missing or malformed fails here, naming the variable, rather than
 * as a request to an address nobody set.
 */
final readonly class InvestmentsConfig
{
    /**
     * @param array<string, ProgrammeOrders> $programmes by programme
     * @param int<1, 5000> $writeChunk
     */
    public function __construct(
        public BvbSource $bvb,
        public int $processingTimeoutSeconds,
        public string $ordersActType,
        public array $programmes,
        public SubscriptionLists $subscriptions,
        public int $writeChunk,
        public string $queue,
        public ApiLimits $api,
    ) {
    }

    public function orders(Programme $programme): ProgrammeOrders
    {
        return $this->programmes[$programme->value];
    }

    public static function read(ConfigRepository $config, OfficialSourceRegistry $sources): self
    {
        $bvb = $sources->find(BvbSource::CODE);
        $url = static fn (string $key, string $variable): string => self::url($config->get("investments.bvb.{$key}"), $variable, $bvb);

        $instrumentUrl = $url('instrument_url', 'INVESTMENTS_BVB_INSTRUMENT_URL');
        // Shown, not fetched — but a source with no home page is one set up wrong, and says so.
        self::url($bvb->baseUrl, 'INVESTMENTS_BVB_BASE_URL', $bvb);

        if (substr_count($instrumentUrl, BvbSource::SYMBOL) !== 1) {
            throw new RuntimeException(sprintf('INVESTMENTS_BVB_INSTRUMENT_URL must hold %s exactly once.', BvbSource::SYMBOL));
        }

        return new self(
            bvb: new BvbSource(
                sharesUrl: $url('shares_url', 'INVESTMENTS_BVB_SHARES_URL'),
                bondsUrl: $url('bonds_url', 'INVESTMENTS_BVB_BONDS_URL'),
                dividendsUrl: $url('dividends_url', 'INVESTMENTS_BVB_DIVIDENDS_URL'),
                instrumentUrl: $instrumentUrl,
                timezone: self::timezone($config->get('investments.bvb.timezone'), 'INVESTMENTS_BVB_TIMEZONE'),
            ),
            processingTimeoutSeconds: self::whole($config->get('investments.processing_timeout_seconds'), 1, 600, 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'),
            ordersActType: self::text($config->get('investments.orders.act_type'), '/\A[A-Z]{2,32}\z/', 'INVESTMENTS_ORDERS_ACT_TYPE'),
            programmes: array_combine(
                array_map(static fn (Programme $p): string => $p->value, Programme::cases()),
                array_map(static fn (Programme $p): ProgrammeOrders => self::programme($config, $p), Programme::cases()),
            ),
            subscriptions: new SubscriptionLists(
                pageUrl: self::url($config->get('investments.subscriptions.page_url'), 'INVESTMENTS_SUBSCRIPTIONS_PAGE_URL', $sources->find(SubscriptionLists::SOURCE)),
                filePatterns: array_combine(
                    array_map(static fn (Programme $p): string => $p->value, Programme::cases()),
                    array_map(static fn (Programme $p): string => self::pattern($config->get("investments.subscriptions.file_patterns.{$p->value}"), "INVESTMENTS_SUBSCRIPTIONS_{$p->value}_FILE"), Programme::cases()),
                ),
            ),
            writeChunk: max(1, min(5000, self::whole($config->get('investments.write_chunk'), 1, 5000, 'INVESTMENTS_WRITE_CHUNK'))),
            queue: self::text($config->get('investments.queue'), '/\A[a-z0-9_-]{1,64}\z/', 'INVESTMENTS_QUEUE'),
            api: new ApiLimits(
                pageSize: min(200, max(1, self::whole($config->get('investments.api.page_size'), 1, 200, 'INVESTMENTS_API_PAGE_SIZE'))),
                maxSpanDays: self::whole($config->get('investments.api.max_span_days'), 1, 3660, 'INVESTMENTS_API_MAX_SPAN_DAYS'),
                profileRefreshHours: self::whole($config->get('investments.api.profile_refresh_hours'), 1, 720, 'INVESTMENTS_PROFILE_REFRESH_HOURS'),
                reads: self::budget($config, 'reads_per_user', 'READS'),
                refreshes: self::budget($config, 'refreshes_per_user', 'REFRESHES'),
            ),
        );
    }

    /** @return array{int, int} attempts, and the seconds they are counted over */
    private static function budget(ConfigRepository $config, string $key, string $name): array
    {
        return [
            self::whole($config->get("investments.api.{$key}.max_attempts"), 1, 100000, "INVESTMENTS_API_{$name}_MAX_ATTEMPTS"),
            self::whole($config->get("investments.api.{$key}.decay_seconds"), 1, 86400, "INVESTMENTS_API_{$name}_DECAY_SECONDS"),
        ];
    }

    private static function programme(ConfigRepository $config, Programme $programme): ProgrammeOrders
    {
        return new ProgrammeOrders(
            programme: $programme,
            titlePattern: self::pattern($config->get("investments.orders.title_patterns.{$programme->value}"), "INVESTMENTS_{$programme->value}_TITLE_PATTERN"),
        );
    }

    /** A whole number in its range, as `.env` writes one; anything else fails, naming the variable. */
    private static function whole(mixed $value, int $min, int $max, string $variable): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        if (! is_int($number)) {
            throw new RuntimeException(sprintf('%s must be a whole number, %d to %d.', $variable, $min, $max));
        }

        return $number;
    }

    /** Text of the shape asked for; anything else fails, naming the variable. */
    private static function text(mixed $value, string $shape, string $variable): string
    {
        if (! is_string($value) || preg_match($shape, $value) !== 1) {
            throw new RuntimeException(sprintf('%s is missing or not as its comment in .env.example says.', $variable));
        }

        return $value;
    }

    /** A delimited PCRE pattern that compiles, as a law watch's must; anything else fails, naming the variable. */
    private static function pattern(mixed $value, string $variable): string
    {
        return LawWatchPattern::compiles($value) && is_string($value) ? $value : throw new RuntimeException(sprintf('%s must be a PCRE pattern, as /…/u.', $variable));
    }

    /** A time zone by its IANA name, `Europe/Bucharest`; anything else fails, naming the variable. */
    private static function timezone(mixed $value, string $variable): DateTimeZone
    {
        if (! is_string($value) || ! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException(sprintf('%s must be a time zone, as Europe/Bucharest.', $variable));
        }

        return new DateTimeZone($value);
    }

    /** An https address on a host the source allows — the fetcher's own rule — or it fails, naming the variable. */
    private static function url(mixed $value, string $variable, SourceDefinition $source): string
    {
        if (! is_string($value) || ! $source->allowsUrl($value)) {
            throw new RuntimeException(sprintf('%s must be an https address on a host the %s source allows (%s).', $variable, $source->code, implode(', ', $source->allowedHosts)));
        }

        return $value;
    }
}
