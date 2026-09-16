<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\DataTransferObjects;

/**
 * What came back from a model: the answer, or why there isn't one.
 *
 * ## Why a failure has a name
 *
 * Because one of them is fixable and the rest are not. A thinking model that spends its whole token
 * budget before writing anything returns an empty string and a `length` finish reason — the call
 * succeeded, the bill was paid, and the answer is missing. Silently treating that as "no suggestion"
 * sends a person to fill in the form by hand and leaves no trace of why, when the actual fix is one
 * number in `statement_import.ai.max_tokens`. So it is {@see NO_ROOM}, kept on the import beside the
 * empty suggestion, and the screen can say so.
 *
 * The others are the ordinary ones: nobody to ask, and an answer that was not JSON.
 */
final readonly class AiAnswer
{
    /** No consent, AI switched off, or no provider configured — expected, and not an error. */
    public const string UNAVAILABLE = 'UNAVAILABLE';

    /** The provider refused, timed out, or could not be reached. */
    public const string REFUSED = 'REFUSED';

    /** The model thought until its budget ran out and never wrote the answer. */
    public const string NO_ROOM = 'NO_ROOM';

    /** Something came back, but it was not the JSON object that was asked for. */
    public const string UNREADABLE = 'UNREADABLE';

    /** @param array<string, mixed>|null $data */
    private function __construct(
        public ?array $data,
        public ?string $failure,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function of(array $data): self
    {
        return new self($data, null);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->data !== null;
    }
}
