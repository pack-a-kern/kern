<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Bvb;

/** A bond's type, as BVB's bond list writes it in its "Tip" column. A type not here fails the list. */
enum BvbBondType: string
{
    case GOVERNMENT = 'Titluri de stat';
    case CORPORATE = 'Obligatiuni corporative';
    case MUNICIPAL = 'Obligatiuni municipale';
}
