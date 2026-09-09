<?php

declare(strict_types=1);

namespace App\Domain\Spv\DataTransferObjects;

use App\Domain\Spv\ValueObjects\SensitiveString;

final readonly class SubmitSpvCredentialsInput
{
    public function __construct(
        public string $sessionId,
        public string $username,
        public SensitiveString $password,
        public ?string $actorId,
    ) {
    }
}
