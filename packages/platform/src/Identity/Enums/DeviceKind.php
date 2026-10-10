<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Enums;

/**
 * What sort of thing a session was opened from, as far as its user agent admits.
 *
 * For the device list, where the question a person is answering is "do I recognise this?" — and a
 * phone, a laptop and something automated are the three answers that change what they do next.
 *
 * ## This is decoration, and must stay decoration
 *
 * A user agent is a string the client chooses. It is trivially forged, and anything deciding access
 * from it would be deciding from an attacker's input. Nothing here is consulted by a guard, a policy
 * or a gate; it exists so a row can carry a recognisable mark beside an address and a time.
 */
enum DeviceKind: string
{
    case DESKTOP = 'desktop';
    case MOBILE = 'mobile';
    case TABLET = 'tablet';

    /**
     * Something automated — a crawler, a monitor, a headless browser.
     *
     * Worth separating rather than folding into `desktop`: a session opened by one is either this
     * installation's own tooling or something the owner should look at, and both are better served
     * by saying so than by drawing a laptop.
     */
    case ROBOT = 'robot';

    /** No user agent was recorded, or none of the above could be read out of it. */
    case UNKNOWN = 'unknown';
}
