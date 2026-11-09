<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/** `legislation.watches`, read once and checked: a value missing or malformed fails, naming its variable. */
final readonly class WatchSettings
{
    /**
     * @param non-empty-list<int> $intervals the hours a person may choose
     * @param list<WatchDeclaration> $declared
     */
    public function __construct(
        public int $lockSeconds,
        public int $overlapDays,
        public array $intervals,
        public array $declared,
    ) {
    }

    public static function read(ConfigRepository $config): self
    {
        $whole = static function (mixed $value, int $min, int $max, string $variable): int {
            $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

            return is_int($number) ? $number : throw new RuntimeException("{$variable} must be a whole number, {$min} to {$max}.");
        };
        $intervals = array_map(
            static fn (string $hours): int => $whole(trim($hours), 1, 24 * 366, 'LEGISLATION_WATCHES_INTERVALS'),
            explode(',', is_string($config->get('legislation.watches.intervals')) ? $config->get('legislation.watches.intervals') : throw new RuntimeException('LEGISLATION_WATCHES_INTERVALS must list the hours a person may choose, as 24,168,720.')),
        );
        $declared = $config->get('legislation.watches.declared', []);

        return new self(
            lockSeconds: $whole($config->get('legislation.watches.lock_seconds'), 1, 86400, 'LEGISLATION_WATCHES_LOCK_SECONDS'),
            overlapDays: $whole($config->get('legislation.watches.overlap_days'), 0, 90, 'LEGISLATION_WATCHES_OVERLAP_DAYS'),
            intervals: $intervals,
            declared: array_values(array_map(
                static fn (mixed $entry, string|int $key): WatchDeclaration => WatchDeclaration::fromConfig((string) $key, is_array($entry) ? $entry : []),
                is_array($declared) ? $declared : [],
                array_keys(is_array($declared) ? $declared : []),
            )),
        );
    }
}
