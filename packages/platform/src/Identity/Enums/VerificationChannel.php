<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Enums;

/**
 * How a verification code reaches the person it is meant for.
 *
 * A closed enum rather than a free string because the value decides which sender runs, and a channel
 * arriving from a request body would be a request choosing what code this application sends where.
 *
 * `EMAIL` is the only case today. A second one — WhatsApp, SMS, a code read out on the phone — is a
 * case here plus an implementation of `Contracts\VerificationSender`, and nothing else: the challenge
 * table already carries `channel`, the middleware never asks how a code travelled, and the screen
 * shows whatever the sender says it sent to. That seam is deliberate; the integration behind it is
 * not built, because building an integration nobody has asked for yet is how a codebase fills up with
 * code that has never run.
 */
enum VerificationChannel: string
{
    case EMAIL = 'EMAIL';

    /**
     * What a person is told the code went to, as a translation key rather than a sentence.
     *
     * The wording lives in the dictionaries with everything else a person reads; this only says which
     * entry. Nothing user-facing is written in PHP.
     */
    public function descriptionKey(): string
    {
        return match ($this) {
            self::EMAIL => 'channels.email',
        };
    }
}
