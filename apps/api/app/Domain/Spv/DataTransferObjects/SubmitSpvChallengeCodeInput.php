<?php

declare(strict_types=1);

namespace App\Domain\Spv\DataTransferObjects;

use App\Domain\Spv\ValueObjects\SensitiveString;

final readonly class SubmitSpvChallengeCodeInput
{
    public function __construct(
        public string $sessionId,
        public SensitiveString $code,
        public ?string $actorId,
    ) {
    }
}
