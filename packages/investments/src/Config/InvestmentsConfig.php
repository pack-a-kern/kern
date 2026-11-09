<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Config\OfficialSourceRegistry;
use Kern\Legislation\DataTransferObjects\SourceDefinition;
use RuntimeException;

/**
 * This package's configuration, read once and validated (ADR 0025 §4). The only class that reads
 * `config('investments.…')`: a value missing or malformed fails here, naming the variable, rather than
 * as a request to an address nobody set.
 */
final readonly class InvestmentsConfig
{
    public function __construct(
        public BvbSource $bvb,
        public int $processingTimeoutSeconds,
    ) {
    }

    public static function read(ConfigRepository $config, OfficialSourceRegistry $sources): self
    {
        $bvb = $sources->find(BvbSource::CODE);
        $url = static fn (string $key, string $variable): string => self::url($config->get("investments.bvb.{$key}"), $variable, $bvb);

        $instrumentUrl = $url('instrument_url', 'INVESTMENTS_BVB_INSTRUMENT_URL');

        if (substr_count($instrumentUrl, BvbSource::SYMBOL) !== 1) {
            throw new RuntimeException(sprintf('INVESTMENTS_BVB_INSTRUMENT_URL must hold %s exactly once.', BvbSource::SYMBOL));
        }

        return new self(bvb: new BvbSource(
            sharesUrl: $url('shares_url', 'INVESTMENTS_BVB_SHARES_URL'),
            bondsUrl: $url('bonds_url', 'INVESTMENTS_BVB_BONDS_URL'),
            dividendsUrl: $url('dividends_url', 'INVESTMENTS_BVB_DIVIDENDS_URL'),
            instrumentUrl: $instrumentUrl,
            timezone: self::timezone($config->get('investments.bvb.timezone'), 'INVESTMENTS_BVB_TIMEZONE'),
        ), processingTimeoutSeconds: self::seconds($config->get('investments.processing_timeout_seconds'), 'INVESTMENTS_PROCESSING_TIMEOUT_SECONDS'));
    }

    /** A whole number of seconds, 1 to 600, as `.env` writes one; anything else fails, naming the variable. */
    private static function seconds(mixed $value, string $variable): int
    {
        $seconds = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 600]]);

        if (! is_int($seconds)) {
            throw new RuntimeException(sprintf('%s must be a whole number of seconds, 1 to 600.', $variable));
        }

        return $seconds;
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
