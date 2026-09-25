<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserPasskey;
use Kern\Platform\Identity\Support\Webauthn\PasskeyManager;

/**
 * Finishes a passkey-registration ceremony (`Kern\Platform\Identity\Support\Webauthn\PasskeyManager::
 * creationOptionsFor()` began it) and records the audit trail. No "begin" counterpart action
 * exists — issuing a challenge mutates nothing worth auditing, so `App\Http\Controllers\Api\V1\
 * Auth\PasskeyController` calls `PasskeyManager::creationOptionsFor()` directly for that step
 * (`docs/CODE-QUALITY.md` §1 — a wrapper adding no logic is not worth the indirection).
 */
final readonly class CompletePasskeyRegistration
{
    public function __construct(
        private AuditChainService $audit,
        private PasskeyManager $passkeys,
    ) {
    }

    /**
     * @param array<string, mixed> $credentialJson the browser's `navigator.credentials.create()` response, decoded from JSON
     */
    public function __invoke(User $user, array $credentialJson, string $host, ?string $name): UserPasskey
    {
        $passkey = $this->passkeys->verifyRegistration($user, $credentialJson, $host, $name);

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'PasskeyRegistered',
            resourceType: 'UserPasskey',
            resourceId: $passkey->id,
            payloadHash: $this->audit->hashPayload(['aaguid' => $passkey->aaguid]),
        ));

        return $passkey;
    }
}
