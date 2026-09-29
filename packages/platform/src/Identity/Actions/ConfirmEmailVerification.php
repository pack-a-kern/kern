<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Identity\Contracts\VerificationSender;
use Kern\Platform\Identity\Exceptions\InvalidVerificationCodeException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserVerificationChallenge;

/**
 * Checks a submitted code and, if it is right, marks the address verified.
 *
 * ## Every live challenge is tried, newest first
 *
 * Asking for a second code before the first expires is ordinary — somebody did not receive it, or it
 * arrived late — and both codes stay valid until one is used. Accepting only the newest would
 * silently break the code already sitting in their inbox.
 *
 * ## A wrong guess costs an attempt on every candidate
 *
 * Not just on the one that happened to match nothing: otherwise a caller could exhaust a budget on
 * one row and keep guessing against another by simply asking for more codes. The ceiling is per
 * challenge and every candidate pays, so requesting codes cannot buy attempts.
 *
 * ## The destination is re-checked
 *
 * A code proves somebody read one particular address. If the account's address changed after the code
 * went out, that proof is about an address the account no longer has, and it is refused. The blind
 * index answers this without a second plaintext of the address anywhere.
 *
 * ## Consuming and marking are one transaction
 *
 * `consumed_at` and `email_verified_at` must move together or neither: a code burnt without the
 * account being verified strands the person, and an account verified while the code stays live leaves
 * a working code in an inbox.
 */
final readonly class ConfirmEmailVerification
{
    public function __construct(
        private VerificationSender $sender,
        private BlindIndexHasher $blindIndex,
        private AuditChainService $audit,
        private ConfigRepository $config,
    ) {
    }

    /** @throws InvalidVerificationCodeException */
    public function __invoke(User $user, string $code): User
    {
        $submitted = trim($code);
        $maxAttempts = max(1, $this->config->integer('identity.verification.max_attempts'));

        $candidates = UserVerificationChallenge::query()
            ->live((string) $user->id, $this->sender->channel())
            ->get();

        $destination = $this->sender->canReach($user)
            ? $this->blindIndex->compute($this->sender->destination($user))
            : null;

        $matched = null;

        foreach ($candidates as $challenge) {
            if ($challenge->isExhausted($maxAttempts)) {
                continue;
            }

            // Recorded before the comparison, so a process killed between the two has counted the
            // guess rather than forgotten it.
            $challenge->increment('attempts');

            if ($destination !== null
                && hash_equals($challenge->destination_blind_index, $destination)
                && Hash::check($submitted, $challenge->code_hash)) {
                $matched = $challenge;

                break;
            }
        }

        if ($matched === null) {
            throw InvalidVerificationCodeException::create();
        }

        DB::transaction(function () use ($matched, $user): void {
            $matched->consumed_at = Carbon::now('UTC');
            $matched->save();

            $user->email_verified_at = Carbon::now('UTC');
            $user->save();
        });

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'EmailVerified',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload([
                'channel' => $this->sender->channel()->value,
                'destination_blind_index' => $matched->destination_blind_index,
                'attempts' => $matched->attempts,
            ]),
        ));

        return $user;
    }
}
