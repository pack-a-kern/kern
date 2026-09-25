<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Rental\Enums;

/**
 * `rental_contract_versions.payment_frequency` — how often rent falls due under one version of a
 * contract's terms. Deliberately a second, independent enum from
 * `Kern\TaxRomania\Wallet\ValueObjects\Money`'s sibling `Kern\TaxRomania\Wallet\Enums\PaymentFrequency`
 * (which describes a *deposit's* payment/capitalisation cadence): `docs/AGENTS.md` §6 forbids a
 * module importing another module's types outside the DTO/contract/event shapes `deptrac`
 * enforces, and a plain finite-state enum is exactly the kind of infrastructure-shaped type
 * `docs/handoff/021-wallet.md` ("Key decisions", the `Money`/`Decimal` duplication reasoning)
 * already established each module should own independently rather than import. No `AT_MATURITY`
 * case here — unlike a deposit, a lease's rent is never "paid once at the end", so that case would
 * be untested, unreachable dead code in this module.
 */
enum RentalPaymentFrequency: string
{
    case MONTHLY = 'MONTHLY';
    case QUARTERLY = 'QUARTERLY';
    case SEMI_ANNUAL = 'SEMI_ANNUAL';
    case ANNUAL = 'ANNUAL';
}
