<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\DataTransferObjects\AuditEventInput;
use App\Domain\Audit\Services\AuditChainService;
use App\Domain\Identity\Enums\MfaMethodType;
use App\Domain\Identity\Exceptions\TotpNotEnrolledException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\RecoveryCodes\RecoveryCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Invalidates every existing recovery code for a user and issues a fresh set — the "I used most of
 * mine, or I think they leaked" flow, independent of `App\Domain\Identity\Actions\
 * ConfirmTotpEnrollment` (which only runs once, at first enrollment).
 *
 * Requires an already-**confirmed** TOTP method (`TotpNotEnrolledException` otherwise) for the
 * same reason `App\Domain\Identity\Support\RecoveryCodes\RecoveryCodeGenerator`'s docblock gives:
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
