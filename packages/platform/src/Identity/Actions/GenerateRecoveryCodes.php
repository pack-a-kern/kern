<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Identity\Enums\MfaMethodType;
use Kern\Platform\Identity\Exceptions\TotpNotEnrolledException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Support\RecoveryCodes\RecoveryCodeGenerator;

/**
 * Invalidates every existing recovery code for a user and issues a fresh set — the "I used most of
 * mine, or I think they leaked" flow, independent of `Kern\Platform\Identity\Actions\
 * ConfirmTotpEnrollment` (which only runs once, at first enrollment).
 *
 * Requires an already-**confirmed** TOTP method (`TotpNotEnrolledException` otherwise) for the
 * same reason `Kern\Platform\Identity\Support\RecoveryCodes\RecoveryCodeGenerator`'s docblock gives:
 * a recovery code is only ever useful as a TOTP-challenge fallback.
 *
 */
final readonly class GenerateRecoveryCodes
{
    public function __construct(
        private AuditChainService $audit,
        private RecoveryCodeGenerator $recoveryCodes,
    ) {
    }

    /**
     * @return list<string> the new plaintext codes — shown once
     */
    public function __invoke(User $user): array
    {
        $hasConfirmedTotp = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNotNull('confirmed_at')
            ->exists();

        if (! $hasConfirmedTotp) {
            throw TotpNotEnrolledException::create();
        }

        $codes = $this->recoveryCodes->generate();

        DB::transaction(function () use ($user, $codes): void {
            $user->recoveryCodes()->delete();

            foreach ($codes as $plainCode) {
                $user->recoveryCodes()->create([
                    'code_hash' => Hash::make($plainCode),
                ]);
            }
        });

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'RecoveryCodesRegenerated',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['count' => count($codes)]),
        ));

        return $codes;
    }
}
