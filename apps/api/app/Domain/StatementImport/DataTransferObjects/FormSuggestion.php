<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\DataTransferObjects;

/**
 * A proposed form, or the reason there isn't one.
 *
 * The reason is kept because the two that matter look identical on screen and have opposite fixes:
 * a person with no AI consent is meant to fill the form in themselves, while a model that ran out of
 * room is a number in `statement_import.ai.max_tokens` that nobody would think to change if the
 * screen only said "no suggestion".
 */
final readonly class FormSuggestion
{
    /** The reply named a column or a label the file does not have. */
    public const string NOT_THIS_FILE = 'NOT_THIS_FILE';

    private function __construct(
        public ?StatementForm $form,
        public ?string $failure,
    ) {
    }

    public static function of(StatementForm $form): self
    {
        return new self($form, null);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }
}
