<?php

declare(strict_types=1);

namespace Kern\Investments\Config;

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

        return new self(new BvbSource(
            sharesUrl: $url('shares_url', 'INVESTMENTS_BVB_SHARES_URL'),
            bondsUrl: $url('bonds_url', 'INVESTMENTS_BVB_BONDS_URL'),
            dividendsUrl: $url('dividends_url', 'INVESTMENTS_BVB_DIVIDENDS_URL'),
            instrumentUrl: $instrumentUrl,
        ));
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
