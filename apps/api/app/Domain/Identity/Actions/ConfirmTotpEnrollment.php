<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\MfaMethodType;
use App\Domain\Identity\Exceptions\InvalidMfaCodeException;
use App\Domain\Identity\Exceptions\TotpNotEnrolledException;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserMfaMethod;
use App\Domain\Identity\Support\RecoveryCodes\RecoveryCodeGenerator;
use App\Domain\Identity\Support\Totp\TotpVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;

/**
 * Proves possession of the secret `App\Domain\Identity\Actions\EnrollTotp` generated, activates
 * the method (`confirmed_at`), and — in the same transaction — replaces the account's recovery
 * codes with a fresh set (`BLUEPRINT.md` §4.7). Recovery codes only make sense once a TOTP challenge
 * can actually occur at login (`App\Domain\Identity\Support\RecoveryCodes\
 * RecoveryCodeGenerator`'s docblock), so generating them here, not at `EnrollTotp` time, ties them
 * to the moment MFA actually becomes active.
 *
 */
final readonly class ConfirmTotpEnrollment
{
    public function __construct(
        private AuditChainService $audit,
        private TotpVerifier $totp,
        private RecoveryCodeGenerator $recoveryCodes,
    ) {
    }

    /**
     * @return list<string> the plaintext recovery codes — shown to the user exactly once; only
     *                       their `Hash::make()` digests are ever persisted.
     */
    public function __invoke(User $user, string $code): array
    {
        /** @var UserMfaMethod|null $method */
        $method = $user->mfaMethods()
            ->where('type', MfaMethodType::TOTP)
            ->whereNull('confirmed_at')
            ->first();

        if ($method === null) {
            throw TotpNotEnrolledException::create();
        }

        if (! $this->totp->verify($method->secret_ciphertext, $code)) {
            throw InvalidMfaCodeException::create();
        }

        $codes = $this->recoveryCodes->generate();

        DB::transaction(function () use ($user, $method, $codes): void {
            $method->confirmed_at = now('UTC');
            $method->save();

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
            eventType: 'MfaEnrolled',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload(['method' => MfaMethodType::TOTP->value, 'recovery_codes' => count($codes)]),
        ));

        return $codes;
    }
}
