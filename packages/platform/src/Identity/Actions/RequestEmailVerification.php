<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Actions;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Kern\Audit\DataTransferObjects\AuditEventInput;
use Kern\Audit\Services\AuditChainService;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Identity\Contracts\VerificationSender;
use Kern\Platform\Identity\Exceptions\VerificationUndeliverableException;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\Identity\Models\UserVerificationChallenge;

/**
 * Issues a verification code and sends it.
 *
 * ## The order matters
 *
 * The challenge row is written *before* the code is sent, and the send is not rolled back if it
 * throws. A code that was delivered but not recorded can never be accepted — the person holds
 * something this application has no memory of — while a code recorded but not delivered is merely
 * unused, and expires on its own. Of the two ways to be wrong, only one strands somebody.
 *
 * ## The code is generated here and never stored
 *
 * `random_int` rather than `rand`: this is a secret, briefly, and a predictable one is no secret at
 * all. Only its hash reaches the database, for the same reason a recovery code's does — a table read
 * by an attacker must not hand out working codes. The plaintext exists between this method and the
 * sender, and nowhere else: it is not returned, not logged, not put in an audit payload.
 *
 * ## What the audit event records
 *
 * That a code was requested, on which channel — never the code, and never the address. `payloadHash`
 * is a hash, but an address hashed under a known algorithm with a guessable input space is not
 * protected, so the destination goes in as the blind index the challenge already stores.
 */
final readonly class RequestEmailVerification
{
    public function __construct(
        private VerificationSender $sender,
        private BlindIndexHasher $blindIndex,
        private AuditChainService $audit,
        private ConfigRepository $config,
    ) {
    }

    /** @throws VerificationUndeliverableException */
    public function __invoke(User $user): UserVerificationChallenge
    {
        if (! $this->sender->canReach($user)) {
            throw VerificationUndeliverableException::create();
        }

        $ttl = max(60, $this->config->integer('identity.verification.ttl_seconds'));
        $code = $this->code();
        $destination = $this->blindIndex->compute($this->sender->destination($user));

        $challenge = new UserVerificationChallenge([
            'user_id' => $user->id,
            'channel' => $this->sender->channel(),
            'destination_blind_index' => $destination,
            'code_hash' => Hash::make($code),
            'expires_at' => Carbon::now('UTC')->addSeconds($ttl),
        ]);
        $challenge->save();

        $this->audit->record(AuditEventInput::occurringNow(
            subjectId: null,
            actorId: $user->id,
            eventType: 'EmailVerificationRequested',
            resourceType: 'User',
            resourceId: $user->id,
            payloadHash: $this->audit->hashPayload([
                'channel' => $this->sender->channel()->value,
                'destination_blind_index' => $destination,
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ]),
        ));

        $this->sender->send($user, $code, $ttl);

        return $challenge;
    }

    /**
     * A zero-padded decimal code of the configured length.
     *
     * Digits because they survive every channel this may ever use — an email, a message, a telephone
     * call — and because a person retyping one from another device makes fewer mistakes with them.
     * Short on purpose: the attempt ceiling is what makes it safe, not its length.
     */
    private function code(): string
    {
        $length = min(10, max(4, $this->config->integer('identity.verification.code_length')));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
