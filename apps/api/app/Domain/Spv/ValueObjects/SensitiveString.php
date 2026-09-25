<?php

declare(strict_types=1);

namespace App\Domain\Spv\ValueObjects;

use Stringable;

/**
 * Wraps an SPV password or one-time challenge code (`BLUEPRINT.md` §13.2 steps 3/6 — "parola NU se
 * persistă implicit", "codul ... nu se loghează") from the moment it enters this codebase until
 * the single call site that must see the real value (`App\Domain\Spv\Transport\
 * HttpSpvBrowserSessionClient`).
 *
 * Mirrors `Kern\Documents\Extraction\DataTransferObjects\UntrustedDocumentText`'s exact
 * "wrap at the boundary, reveal only at the one sanctioned crossing" shape, plus a `__debugInfo()`
 * override that plain `Stringable` redaction does not cover: `var_dump()`/`dd()` bypass
 * `__toString()` entirely and print private properties verbatim, which is exactly the accidental-
 * exposure path a stray debug statement or an exception handler's context dump would take.
 */
final readonly class SensitiveString implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function wrap(string $value): self
    {
        return new self($value);
    }

    /** The one explicit, grep-able way the real value leaves this type. */
    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return 'SensitiveString(<redacted>)';
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '<redacted>'];
    }
}
