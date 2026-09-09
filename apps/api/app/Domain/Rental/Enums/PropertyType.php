<?php

declare(strict_types=1);

namespace App\Domain\Rental\Enums;

/**
 * `properties.type` (`BLUEPRINT.md` §6.5 names the column, not its literal values). A minimal,
 * real-estate-shaped set — this module has no opinion on how a value here maps to a fiscal
 * category (`docs/AGENTS.md` §6: that mapping, if any, belongs to `Domain/TaxEngine`).
 */
enum PropertyType: string
{
    case APARTMENT = 'APARTMENT';
    case HOUSE = 'HOUSE';
    case LAND = 'LAND';
    case COMMERCIAL = 'COMMERCIAL';
    case OTHER = 'OTHER';
}
