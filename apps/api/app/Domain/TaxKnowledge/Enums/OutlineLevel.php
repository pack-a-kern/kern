<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Enums;

/**
 * A heading of a law's table of contents, outermost first.
 *
 * Kept apart from {@see SectionType}: a heading has a name and no words of its own to cite, and a
 * provision is cited without it — "Art. 83", never "Titlul IV Art. 83".
 */
enum OutlineLevel: string
{
    case TITLU = 'TITLU';
    case CAPITOL = 'CAPITOL';
    case SECTIUNE = 'SECTIUNE';
}
