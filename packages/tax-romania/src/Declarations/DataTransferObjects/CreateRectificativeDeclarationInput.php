<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\DataTransferObjects;

/**
 * `Kern\TaxRomania\Declarations\Actions\CreateRectificativeDeclaration`'s input. `formVersionId`
 * defaults to the original's own when omitted — the common case, a correction of the same filing
 * period under the same form version; a caller may override it for the rare case a correction must
 * be filed under a different (e.g. newly retired-and-replaced) version.
 */
final readonly class CreateRectificativeDeclarationInput
{
    public function __construct(
        public string $originalDeclarationId,
        public string $assessmentId,
        public ?string $formVersionId = null,
    ) {
    }
}
