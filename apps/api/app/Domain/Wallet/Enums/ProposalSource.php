<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * Who decided what a transaction was.
 *
 * The distinction earns its place in two behaviours: a remembered rule a person wrote is never
 * overwritten by one a model proposed, and a screen can say where a proposal in front of someone
 * came from — "you said this last month" reads very differently from "a model thinks so".
 */
enum ProposalSource: string
{
    case PERSON = 'PERSON';
    case AI = 'AI';
}
