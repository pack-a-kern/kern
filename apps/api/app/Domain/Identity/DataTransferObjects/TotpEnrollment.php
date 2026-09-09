<?php

declare(strict_types=1);

namespace App\Domain\Identity\DataTransferObjects;

/**
 * What `App\Domain\Identity\Actions\EnrollTotp` returns — shown to the user exactly once so they
 * can add it to an authenticator app. Neither field is ever logged or audited (`docs/AGENTS.md`
 * §8): `App\Domain\Identity\Actions\EnrollTotp`'s own audit event carries no payload beyond the
 * method type.
 */
final readonly class TotpEnrollment
{
    public function __construct(
        public string $secret,
        public string $provisioningUri,
    ) {
    }
}
