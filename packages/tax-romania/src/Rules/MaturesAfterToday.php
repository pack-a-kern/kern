<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Kern\TaxRomania\Wallet\Services\DepositTerm;
use Throwable;

/**
 * A term whose maturity, worked out from the deposit's start, falls after today.
 *
 * Maturity closes a deposit, so one entered already matured would be settled the night it was typed in.
 * Checked on what a person enters, never in `OpenFinancialAccount`: a deposit that matured in the past is
 * a valid fact for recording a previous year's interest by other means.
 *
 * The start is the request's own, or — editing a deposit, which may leave it out — the one it already has.
 */
final class MaturesAfterToday implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly ?string $storedStart = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $start = $this->data['starts_at'] ?? $this->storedStart;

        // A missing or malformed start, or a term that is not a whole number, is its own field's error.
        if (! is_string($start) || ! is_numeric($value)) {
            return;
        }

        try {
            $maturity = DepositTerm::maturity(CarbonImmutable::parse($start, 'UTC'), (int) $value);
        } catch (Throwable) {
            return;
        }

        if (! $maturity->isAfter(CarbonImmutable::today('UTC'))) {
            $fail(__('tax-romania::wallet.deposit_already_matured'));
        }
    }
}
