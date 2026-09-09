<?php

declare(strict_types=1);

namespace App\Domain\Wallet\DataTransferObjects;

use DateTimeImmutable;

/**
 * The FX provenance chain `BLUEPRINT.md` §7.5 requires attached to a foreign-currency deposit's
 * interest event — see `App\Domain\Wallet\Services\Fx\DepositInterestFxAnnotator`.
 */
final readonly class DepositInterestFxAnnotation
{
    public function __construct(
        public string $perUnitRateDecimal,
        public DateTimeImmutable $rateDate,
        public string $sourceCode,
        public string $method,
        public string $contentSha256,
        public string $sourceDocumentVersionId,
        public int $ronEquivalentMinor,
    ) {
    }
}
