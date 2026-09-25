<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\Webauthn\Exceptions\PasskeyVerificationException;
use Kern\Platform\Identity\Support\Webauthn\PasskeyManager;
use Throwable;

/**
 * Finishes a passkey login ceremony. A successful passkey assertion is, by itself, a complete
 * login — no separate TOTP/recovery-code step follows (`Kern\Platform\Identity\Support\
 * SpvMfaPolicy`'s docblock explains why a passkey already satisfies this project's "strong
 * authentication" bar on its own).
 */
final readonly class CompletePasskeyLogin
{
    public function __construct(
        private AuditChainService $audit,
        private PasskeyManager $passkeys,
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
        DB::table('security_events')->insert([
            'id' => (string) Str::ulid(),
            'event_type' => 'LOGIN_FAILED',
            'user_id' => null,
            'subject_id' => null,
            'context_json' => json_encode(['method' => 'passkey'], JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]);
    }
}
