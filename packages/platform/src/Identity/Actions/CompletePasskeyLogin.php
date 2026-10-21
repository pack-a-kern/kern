<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use Kern\Platform\Identity\Support\Webauthn\PasskeyManager;
use Kern\Platform\Security\RecordSecurityEvent;
use Kern\Platform\Security\SecurityEventType;
use Throwable;

/**
 * Finishes a passkey login ceremony. A successful passkey assertion is, by itself, a complete
 * login — no separate TOTP/recovery-code step follows (`Kern\Platform\Identity\Support\
 * StepUpPolicy`'s docblock explains why a passkey already satisfies this project's "strong
 * authentication" bar on its own).
 */
final readonly class CompletePasskeyLogin
{
    public function __construct(
        private AuditChainService $audit,
        private PasskeyManager $passkeys,
        private RecordSecurityEvent $securityLog,
    ) {
    }

    /**
     * @param array<string, mixed> $credentialJson the browser's `navigator.credentials.get()` response, decoded from JSON
     */
    public function __invoke(string $email, array $credentialJson, string $host): User
    {
        try {
            $user = $this->passkeys->verifyAssertionForEmail($email, $credentialJson, $host);
        } catch (Throwable $exception) {
            $this->recordFailure();

            throw $exception instanceof PasskeyVerificationException
                ? $exception
                : PasskeyVerificationException::fromLibraryFailure($exception);
        }

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'AuthLoginSucceeded',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['method' => 'passkey']),
        ));

        return $user;
    }

    private function recordFailure(): void
    {
        ($this->securityLog)(SecurityEventType::LOGIN_FAILED, null, null, ['method' => 'passkey']);
    }
}
