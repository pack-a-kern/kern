<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Forms;

use App\Domain\TaxKnowledge\Models\FormStructure;

/** A structure file as read: its row, and its text — held while its values are read, never stored. */
final readonly class FormText
{
    public function __construct(
        public FormStructure $structure,
        /** Null when the extraction service would not read the file. */
        public ?string $text,
    ) {
    }
}
