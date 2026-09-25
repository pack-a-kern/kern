<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\DataTransferObjects;

use Kern\TaxRomania\Spv\ValueObjects\SensitiveString;

final readonly class SubmitSpvChallengeCodeInput
{
    public function __construct(
        public string $sessionId,
        public SensitiveString $code,
        public ?string $actorId,
    ) {
    }
}
