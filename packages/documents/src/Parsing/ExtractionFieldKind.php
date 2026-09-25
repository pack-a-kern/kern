<?php

declare(strict_types=1);

namespace Kern\Documents\Parsing;

/**
 * What shape a field in an {@see ExtractionSchema} has.
 *
 * Three, because three is what real documents need and a fourth would be a guess. An invoice's
 * total is one value; its issuer is a group of values about one thing; its lines are a group
 * repeated per article. Nothing read off a Romanian financial document has needed more than that.
 */
enum ExtractionFieldKind
{
    /** One value — a decimal string, an ISO date, a name. */
    case SCALAR;

    /** A group of fields describing one thing: `issuer {name, tax_id, iban}`. */
    case OBJECT;

    /** Zero or more of that group: `items [{name, quantity, …}]`. */
    case LIST;
}
