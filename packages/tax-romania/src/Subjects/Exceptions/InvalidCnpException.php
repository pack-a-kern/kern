<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Exceptions;

use RuntimeException;

/**
 * A CNP this application will not accept, and precisely why.
 *
 * The reason is the point. "Invalid CNP" tells a person nothing they can act on; "the check digit
 * does not match" sends them to the last character, and "this CNP belongs to a non-resident" is not
 * an error about the number at all — it is a statement about what this product computes.
 *
 * Codes travel to the client as `reason` and are translated there (`errors.reasons` in the
 * dictionary), so the message here is for logs and for API callers who read English.
 */
final class InvalidCnpException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function length(): self
    {
        return new self('CNP_LENGTH', 'A CNP is exactly 13 digits.');
    }

    public static function prefix(): self
    {
        return new self('CNP_PREFIX', 'The first digit of a CNP is between 1 and 9.');
    }

    public static function checksum(): self
    {
        return new self('CNP_CHECKSUM', 'The check digit does not match the rest of the CNP.');
    }

    public static function county(): self
    {
        return new self('CNP_COUNTY', 'The CNP does not carry a known county code.');
    }

    public static function birthDate(): self
    {
        return new self('CNP_BIRTH_DATE', 'The CNP carries a birth date that does not exist.');
    }

    public static function futureBirthDate(): self
    {
        return new self('CNP_FUTURE_BIRTH_DATE', 'The CNP carries a birth date that has not come yet.');
    }

    /** Registered by someone else. Says only that — never who, never how many. */
    public static function alreadyRegistered(): self
    {
        return new self('DUPLICATE_CNP', 'A taxpayer with this CNP is already registered.');
    }

    /** Registered by the caller themselves, which is their own data and worth naming. */
    public static function alreadyYours(): self
    {
        return new self('CNP_ALREADY_YOURS', 'You have already registered a taxpayer with this CNP.');
    }

    /**
     * Not a malformed number — a number this product cannot serve.
     *
     * Leading digit 9 is a foreign, non-resident person. They can owe Romanian tax on
     * Romanian-source income, but under rules this application does not implement: different
     * contribution rules, double-taxation treaties, different declarations. Accepting them would
     * mean producing confident, wrong figures for someone who would rely on them.
     */
    public static function nonResident(): self
    {
        return new self(
            'CNP_NON_RESIDENT',
            'This CNP belongs to a non-resident. This application computes Romanian resident taxation only.',
        );
    }
}
