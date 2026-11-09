<?php

declare(strict_types=1);

namespace Kern\Legislation\Watches;

use RuntimeException;

/**
 * A watch a package declares (`legislation.watches.declared.{key}`), checked before it is mirrored: the
 * values come from that package's environment, so one missing or wrong fails here, naming the watch and
 * what is wrong — its variable is in the declaring package's `.env.example`.
 */
final readonly class WatchDeclaration
{
    public function __construct(
        public string $key,
        public string $declaredBy,
        public string $search,
        public string $actType,
        public ?string $titlePattern,
        public int $firstYear,
        public int $everyHours,
    ) {
    }

    /** @param array<array-key, mixed> $entry */
    public static function fromConfig(string $key, array $entry): self
    {
        $by = is_string($entry['declared_by'] ?? null) ? $entry['declared_by'] : 'a package';
        $fail = static fn (string $what): never => throw new RuntimeException("The law watch \"{$key}\" ({$by}): {$what} — see that package's .env.example.");
        $text = static fn (string $field, string $shape): string => is_string($entry[$field] ?? null) && preg_match($shape, $entry[$field]) === 1 ? $entry[$field] : $fail("{$field} is missing or malformed");
        $whole = static function (string $field, int $min, int $max) use ($entry, $fail): int {
            $number = filter_var($entry[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

            return is_int($number) ? $number : $fail("{$field} must be a whole number, {$min} to {$max}");
        };
        $pattern = $entry['title_pattern'] ?? null;

        if ($pattern !== null && ! LawWatchPattern::compiles($pattern)) {
            $fail('title_pattern must be a PCRE pattern, as /…/u');
        }

        return new self(
            key: preg_match('/\A[a-z0-9_.-]{1,64}\z/', $key) === 1 ? $key : $fail('the key must be a few words of a-z, digits, dots'),
            declaredBy: $text('declared_by', '/\A[a-z0-9\/_-]{1,64}\z/'),
            search: $text('search', '/\A\S.{1,198}\S\z/u'),
            actType: $text('act_type', '/\A[A-Z]{2,32}\z/'),
            titlePattern: is_string($pattern) ? $pattern : null,
            firstYear: $whole('first_year', 1990, (int) date('Y')),
            everyHours: $whole('every_hours', 1, 24 * 366),
        );
    }
}
