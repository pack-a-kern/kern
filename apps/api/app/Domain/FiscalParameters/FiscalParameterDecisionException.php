<?php

declare(strict_types=1);

namespace App\Domain\FiscalParameters;

use RuntimeException;

/** A decision on a fiscal value that cannot be made — named, so the screen can say which in the reader's language. */
final class FiscalParameterDecisionException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notACandidate(string $versionId): self
    {
        return new self("Fiscal parameter version {$versionId} is not a candidate: it was decided already.", 'FISCAL_PARAMETER_NOT_CANDIDATE');
    }

    public static function notItsSource(string $versionId, string $sectionId): self
    {
        return new self("Section {$sectionId} is not one of the paragraphs found to say version {$versionId}.", 'FISCAL_PARAMETER_NOT_ITS_SOURCE');
    }

    public static function nothingToCarry(int $year): self
    {
        return new self("The law in force in {$year} was not read to say any value.", 'FISCAL_PARAMETER_NOTHING_TO_CARRY');
    }
}
