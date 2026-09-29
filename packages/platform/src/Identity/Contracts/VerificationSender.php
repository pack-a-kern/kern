<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Contracts;

use Kern\Platform\Identity\Enums\VerificationChannel;
use Kern\Platform\Identity\Models\User;

/**
 * Delivering a verification code over one channel.
 *
 * The seam that keeps `Enums\VerificationChannel::EMAIL` from being the only thing this feature can
 * ever do. Adding WhatsApp is a case on that enum and an implementation of this interface: the
 * challenge table already records which channel a code went out on, the middleware never asks, and
 * the screen shows whatever channel the sender reports. Nothing else moves.
 *
 * An implementation must not log or return the code. It is a secret with a ten-minute life, and the
 * only places it may appear are the message itself and the hash on the challenge row.
 */
interface VerificationSender
{
    public function channel(): VerificationChannel;

    /**
     * Whether this channel can reach this account at all — an address on file, a provider configured.
     *
     * Asked before a challenge is created, so a channel that cannot deliver does not leave a trail of
     * codes nobody received.
     */
    public function canReach(User $user): bool;

    /**
     * The destination, normalised, for the blind index the challenge records.
     *
     * Returned rather than read off the user by the caller, because only the sender knows which field
     * its channel uses — an address for mail, a telephone number for a message.
     */
    public function destination(User $user): string;

    public function send(User $user, string $code, int $ttlSeconds): void;
}
