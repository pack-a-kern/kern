<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Queries;

use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;

/**
 * A fiscal value as a screen shows it, said here from how it is stored, so the screen formats and decides
 * nothing: the minimum wage an amount, a rate a percent, a deadline a day of a month, an exemption a yes or
 * a no, a period so many days, a threshold so many minimum wages — or a range of them, unless both ends are
 * the same, which the law set as one level.
 *
 * And whether a check found while reading asks for a second look, or only informs.
 */
final class FiscalValueDisplay
{
    /** What a check only tells: everything else found while reading wants a second look. */
    private const array INFORMS = ['several_paragraphs', 'dated_by_note', 'in_force_later'];

    /**
     * @param  array<string, mixed>  $value  as stored
     * @return array<string, mixed>  `kind`, and the fields that kind is shown from
     */
    public static function of(FiscalParameterValueType $type, array $value): array
    {
        $number = static fn (mixed $one): ?int => is_int($one) ? $one : null;

        return match (true) {
            $type === FiscalParameterValueType::MONEY => [
                'kind' => 'money',
                'amount_minor' => $number($value['amount_minor'] ?? null) ?? 0,
                'currency' => is_string($value['currency'] ?? null) ? $value['currency'] : null,
            ],
            $type === FiscalParameterValueType::DECIMAL => ['kind' => 'percent', 'ratio' => is_string($value['value'] ?? null) ? $value['value'] : '0'],
            is_int($value['day'] ?? null) && is_int($value['month'] ?? null) => ['kind' => 'day_month', 'day' => $value['day'], 'month' => $value['month']],
            is_bool($value['value'] ?? null) => ['kind' => 'flag', 'holds' => $value['value']],
            is_int($value['days'] ?? null) => ['kind' => 'days', 'days' => $value['days']],
            array_key_exists('floor', $value) || array_key_exists('cap', $value) => ($value['floor'] ?? null) === ($value['cap'] ?? null)
                ? ['kind' => 'wages', 'wages' => [$number($value['floor'] ?? null)]]
                : ['kind' => 'wages_range', 'floor' => $number($value['floor'] ?? null), 'cap' => $number($value['cap'] ?? null)],
            default => [
                'kind' => 'wages',
                'wages' => array_values(array_filter(
                    $type === FiscalParameterValueType::JSON ? (array) ($value['wages'] ?? []) : [$value['value'] ?? null],
                    is_int(...),
                )),
            ],
        };
    }

    /** `warn` for a check that wants a second look, `idle` for one that only informs. */
    public static function tone(string $kind): string
    {
        return in_array($kind, self::INFORMS, true) ? 'idle' : 'warn';
    }
}
