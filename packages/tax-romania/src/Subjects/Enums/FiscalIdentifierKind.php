<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Subjects\Enums;

use Kern\Platform\Crypto\BlindIndex\Normalizer;

/**
 * The kind of value stored in `tax_subjects.fiscal_identifier_ciphertext`/
 * `fiscal_identifier_blind_index` — introduced by ADR 0011 (`docs/adr/0011-canonical-fiscal-
 * identifier.md`) to close a real collision risk: a single shared blind-index column, unqualified
 * by what kind of identifier produced it, put every subject type's identifier in one uniqueness
 * namespace. A CNP-shaped value and an unrelated PFA business identifier that happened to
 * normalise to the same digits would collide under one raw `HMAC(key, value)`, and a lookup that
 * *did* match would not say which kind matched.
 *
 * `keyedValue()` is this enum's whole contract: it binds the kind into the string that gets
 * hashed, so `CNP:1900101221144` and `CUI:1900101221144` can never produce the same blind index —
 * not by coincidence of the underlying digits ever colliding, but because the hashed inputs
 * themselves are always textually distinct for two different kinds (a fixed, closed set of
 * literal prefixes, never derived from user input). See the ADR's "Key-in-hash vs. composite
 * unique" section for why this was chosen over a `UNIQUE (kind, blind_index)` composite
 * constraint instead.
 *
 * `CNP` uses `Normalizer::cnp()` (digit-stripping — a verified fact about CNP's fixed 13-digit
 * shape). `CUI` uses the generic `Normalizer::text()`, the same choice
 * `docs/handoff/034-pfa-extension.md`'s Key decision #3 already made for a PFA registration
 * number: this module has no verified, single format rule for a business fiscal identifier to
 * encode without risking an unverified assumption (`docs/AGENTS.md` §2), so free-text
 * normalisation (trim, collapse whitespace, lowercase, fold diacritics) is what is actually known
 * to be true, not a stronger claim about digits-only shape.
 */
enum FiscalIdentifierKind: string
{
    case CNP = 'CNP';
    case CUI = 'CUI';

    public function normalize(string $rawValue): string
    {
        return match ($this) {
            self::CNP => Normalizer::cnp($rawValue),
            self::CUI => Normalizer::text($rawValue),
        };
    }

    /**
     * The exact string handed to `Kern\Platform\Crypto\BlindIndex\BlindIndexHasher::compute()`.
     * Never store or hash a bare `normalize()` result for this column — it would put this kind
     * back into the single, unqualified uniqueness namespace ADR 0011 removes it from.
     */
    public function keyedValue(string $rawValue): string
    {
        return $this->value . ':' . $this->normalize($rawValue);
    }
}
