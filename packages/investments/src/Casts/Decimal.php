<?php

declare(strict_types=1);

namespace Kern\Investments\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A decimal held as text and computed with `ext-bcmath` (ADR 0025 §2): a SQLite `decimal` is a float,
 * and the suite runs on both databases. Checked before it is written, so nothing but a plain decimal —
 * no exponent, no thousands separator, no locale comma — ever reaches a column.
 *
 * @implements CastsAttributes<string|null, mixed> anything may be set; only a plain decimal is written
 */
final class Decimal implements CastsAttributes
{
    private const string PATTERN = '/\A-?\d{1,18}(?:\.\d{1,12})?\z/';

    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // A text column: the driver hands back the text that was written.
        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s.%s must be a plain decimal.', $model->getTable(), $key));
        }

        return $value;
    }
}
