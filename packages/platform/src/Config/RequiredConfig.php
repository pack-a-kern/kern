<?php

declare(strict_types=1);

namespace Kern\Platform\Config;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Reads a value the code cannot work without, and says which one when it is missing.
 *
 * Every default lives in a `config/*.php` file, beside the variable that overrides it — never here,
 * and never at the call site. This used to be nine private copies with four behaviours: two
 * exception types, one that answered `''` and carried on, and three that fell back to a number
 * written in the code, so a missing setting was silently replaced by a value nobody had configured.
 */
final readonly class RequiredConfig
{
    public function __construct(private Repository $config)
    {
    }

    /** A non-empty string. */
    public function string(string $key): string
    {
        return $this->optionalString($key) ?? throw self::missing($key, 'a non-empty string');
    }

    /** A non-empty string, or null where none is set — for a setting that may be left out. */
    public function optionalString(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A whole number above zero: a timeout, a port, a size, an age.
     *
     * @return positive-int
     */
    public function positiveInt(string $key): int
    {
        $value = $this->config->get($key);

        if (! is_int($value) || $value <= 0) {
            throw self::missing($key, 'a whole number above zero');
        }

        return $value;
    }

    private static function missing(string $key, string $expected): RuntimeException
    {
        return new RuntimeException(sprintf('Config value "%s" must be %s.', $key, $expected));
    }
}
