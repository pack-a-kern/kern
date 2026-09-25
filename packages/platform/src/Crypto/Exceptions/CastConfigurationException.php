<?php

declare(strict_types=1);

namespace Kern\Platform\Crypto\Exceptions;

use RuntimeException;

/**
 * Thrown by `Casts\Encrypted` when the model it's attached to is missing something the cast
 * needs to build a correct AAD binding. These are programmer-error failures (a model
 * misconfiguration), not runtime data failures — they should never occur once a model is wired
 * correctly, and are deliberately loud rather than silently falling back to a weaker binding.
 */
final class CastConfigurationException extends RuntimeException
{
    public static function modelMustImplementContract(string $modelClass): self
    {
        return new self(sprintf(
            '%s must implement %s to use the Encrypted cast — the cast needs to know which ' .
                'TaxSubject\'s DEK protects this row.',
            $modelClass,
            'Kern\Platform\Crypto\Contracts\EncryptsWithSubjectDek',
        ));
    }

    public static function missingPrimaryKey(string $modelClass): self
    {
        return new self(sprintf(
            '%s has no primary key set while an Encrypted attribute is being assigned. Models ' .
                'using the Encrypted cast must assign their UUID primary key in the constructor ' .
                'via Kern\Platform\Crypto\Concerns\HasUuidPrimaryKey, not in a "creating" event — ' .
                'see docs/handoff/005-crypto-core.md.',
            $modelClass,
        ));
    }

    public static function valueMustBeString(string $modelClass, string $field): self
    {
        return new self(sprintf(
            '%s::%s must be a string (or null) to use the Encrypted cast — got a non-string, ' .
                'non-null value.',
            $modelClass,
            $field,
        ));
    }
}
