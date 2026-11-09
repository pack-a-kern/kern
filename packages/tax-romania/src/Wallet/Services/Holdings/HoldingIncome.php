<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services\Holdings;

use Kern\Investments\Enums\InstrumentKind;
use Kern\Investments\Enums\PayoutKind;
use Kern\TaxRomania\Wallet\Services\DepositMovements;

/**
 * Which income a holding's money is, for the tax rules (ADR 0025 §10): the category its movement is filed
 * under. Decided here once — by what the instrument is, not by who paid — so a payout and a sale of the same
 * holding can never be told apart differently.
 *
 * Public debt — the state's, and the administrative-territorial units' (art. 93 (1) a)) — is a category of
 * its own whatever the money is: a coupon, a gain on a sale. A share's or a company bond's gain is a
 * securities gain; a dividend a dividend; a company bond's coupon interest, as a deposit's is.
 */
final class HoldingIncome
{
    public const string PUBLIC_DEBT = 'PUBLIC_DEBT_INCOME';

    public const string SECURITIES_GAIN = 'SECURITIES_GAIN';

    public const string DIVIDENDS = 'DIVIDENDS';

    public static function isPublicDebt(InstrumentKind $kind): bool
    {
        return $kind === InstrumentKind::GOVERNMENT_BOND || $kind === InstrumentKind::MUNICIPAL_BOND;
    }

    /** What a payout is filed under: the face value repaid is the person's own money coming back. */
    public static function ofPayout(InstrumentKind $instrument, PayoutKind $payout): string
    {
        return match (true) {
            $payout === PayoutKind::PRINCIPAL => DepositMovements::TRANSFER,
            $payout === PayoutKind::DIVIDEND => self::DIVIDENDS,
            self::isPublicDebt($instrument) => self::PUBLIC_DEBT,
            default => DepositMovements::INTEREST,
        };
    }

    /** What a gain on a sale is filed under. */
    public static function ofGain(InstrumentKind $instrument): string
    {
        return self::isPublicDebt($instrument) ? self::PUBLIC_DEBT : self::SECURITIES_GAIN;
    }
}
