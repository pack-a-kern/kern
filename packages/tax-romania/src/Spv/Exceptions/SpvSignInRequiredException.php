<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Exceptions;

use RuntimeException;

/**
 * Reading the inbox needs a signed-in browser, and there is not one.
 *
 * Two ways to arrive here, one thing to do about it. Either this application never had a live session
 * for the connection — nobody has signed in, or the one they opened has expired — or it had one and the
 * portal has since let it go, which the playbook sees as its login form reappearing.
 *
 * Separate from `SpvSyncUnavailableException`, which means the machinery is broken. This means the
 * machinery is fine and a person has to do something: sign in again. Telling the two apart is the
 * difference between "try later" and "your sitting ended".
 */
final class SpvSignInRequiredException extends RuntimeException
{
    public static function noLiveSession(): self
    {
        return new self('This connection has no signed-in session. Sign in to SPV before reading the inbox.');
    }

    /**
     * The portal returned its login form in the middle of a session this side believed was live.
     *
     * Worth distinguishing in the message, because it is the case where somebody *did* sign in, saw it
     * succeed, and is now being asked again — which looks like a fault unless it is named.
     */
    public static function sessionLost(): self
    {
        return new self('ANAF ended this session. Sign in again to read the inbox.');
    }
}
