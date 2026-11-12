<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Query;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/** `legislation.search`, read once and checked: a value missing or out of range fails, naming its variable. */
final readonly class SearchSettings
{
    public function __construct(
        public float $weightMeaning,
        public float $weightWords,
        public float $weightLabel,
        public float $minLabel,
        public float $minMeaning,
        public int $results,
        public int $maxResults,
    ) {
    }

    public static function read(ConfigRepository $config): self
    {
        $share = static function (string $key, string $variable) use ($config): float {
            $value = filter_var($config->get("legislation.search.{$key}"), FILTER_VALIDATE_FLOAT);

            return is_float($value) && $value >= 0 && $value <= 1 ? $value : throw new RuntimeException("{$variable} must be a number from 0 to 1.");
        };
        $count = static function (string $key, string $variable) use ($config): int {
            $value = filter_var($config->get("legislation.search.{$key}"), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

            return is_int($value) ? $value : throw new RuntimeException("{$variable} must be a whole number, 1 to 1000.");
        };
        $results = $count('results', 'LEGISLATION_SEARCH_RESULTS');
        $maxResults = $count('max_results', 'LEGISLATION_SEARCH_MAX_RESULTS');

        if ($results > $maxResults) {
            throw new RuntimeException('LEGISLATION_SEARCH_RESULTS must not exceed LEGISLATION_SEARCH_MAX_RESULTS.');
        }

        return new self(
            weightMeaning: $share('weight_meaning', 'LEGISLATION_SEARCH_WEIGHT_MEANING'),
            weightWords: $share('weight_words', 'LEGISLATION_SEARCH_WEIGHT_WORDS'),
            weightLabel: $share('weight_label', 'LEGISLATION_SEARCH_WEIGHT_LABEL'),
            minLabel: $share('min_label', 'LEGISLATION_SEARCH_MIN_LABEL'),
            minMeaning: $share('min_meaning', 'LEGISLATION_SEARCH_MIN_MEANING'),
            results: $results,
            maxResults: $maxResults,
        );
    }
}
