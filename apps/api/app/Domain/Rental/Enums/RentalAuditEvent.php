<?php

declare(strict_types=1);

namespace App\Domain\Rental\Enums;

/**
 * The closed set of rental changes the audit chain records.
 *
 * An enum for the reason `App\Domain\Wallet\Enums\WalletAuditEvent` is one: an event type that
 * varies by a typo is written, hashes validly, and is never found by the search that needed it.
 */
enum RentalAuditEvent: string
{
    case OPENED = 'RentalOpened';
    case UPDATED = 'RentalUpdated';
    case RENT_CHANGED = 'RentalRentChanged';
    case ENDED = 'RentalEnded';
    case DELETED = 'RentalDeleted';

    /** Every rental event points at the contract: the property exists only because it is let. */
    public const string RESOURCE_TYPE = 'RentalContract';
}
