<?php

declare(strict_types=1);

namespace App\Domain\Banks\DataTransferObjects;

use App\Domain\Banks\Parsing\LegalForms;

/** GLEIF's two files, parsed: which BICs each LEI holds, and how legal forms are abbreviated. */
final readonly class BankReferenceFiles
{
    /** @param array<string, list<string>> $bics LEI => its BICs */
    public function __construct(
        public array $bics,
        public LegalForms $legalForms,
        public string $bicListDate,
    ) {
    }
}
