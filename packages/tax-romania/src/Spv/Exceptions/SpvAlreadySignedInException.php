<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Spv\Exceptions;

use RuntimeException;

/**
 * A sign-in was asked for on a connection that is already signed in.
 *
 * Refused rather than started: the browser that is signed in is the one every sync reads in, and a
 * second sign-in would open another browser and ask ANAF to email another code, to replace a sitting
 * that works. The screen never offers the button while a sitting is open; this is the same rule where
 * a request that did not come from the screen also has to meet it.
 */
final class SpvAlreadySignedInException extends RuntimeException
{
    public static function forConnection(): self
    {
        return new self('This connection is already signed in to SPV.');
    }
}
