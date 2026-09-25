<?php

declare(strict_types=1);

namespace Kern\Audit\Support;

use function array_is_list;
use function array_map;

use InvalidArgumentException;

use function is_array;
use function is_float;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use function ksort;

use const SORT_STRING;

/**
 * Deterministic, byte-stable JSON encoding for anything that goes into an
 * `audit_events.event_hash` computation (BLUEPRINT.md §4.8).
 *
 * `event_hash = SHA256(canonical_json(event_without_hash) || previous_hash)` only detects
 * tampering if two independent encodings of the *same logical data* always produce the *same
 * bytes*. PHP's `json_encode()` alone does not guarantee that: associative array key order
 * follows insertion order, and float formatting depends on `serialize_precision`. This class
 * pins every degree of freedom explicitly so the rule never has to be rediscovered later:
 *
 * 1. Object keys are sorted recursively, byte-wise (`ksort(..., SORT_STRING)`), at every
 *    nesting level. Encoding the same associative data with keys inserted in a different order
 *    produces identical output.
 * 2. Compact separators: no whitespace after `,` or `:` (PHP's default `json_encode` behaviour
 *    with no pretty-print flag already does this; stated here so it is a rule, not an accident).
 * 3. Non-ASCII characters are escaped as `\uXXXX` (lower-case hex, PHP's default) rather than
 *    emitted as raw UTF-8 — `JSON_UNESCAPED_UNICODE` is deliberately never passed. This removes
 *    any dependency on the encoding of the string as it happens to sit in memory or on disk.
 * 4. Forward slashes are left unescaped (`JSON_UNESCAPED_SLASHES`) purely for readability of the
 *    stored payload; this is safe because it is applied unconditionally, so it cannot introduce
 *    a second valid encoding of the same data.
 * 5. Floats are rejected outright. PHP's float-to-string conversion is not guaranteed stable
 *    across platforms/ini settings, and BLUEPRINT.md §11.3/§19.1 already forbid floats for money —
 *    every numeric value that can legally appear in an audited event is an integer. A float
 *    anywhere in the structure is a programming error, not a value to format carefully.
 * 6. Every array key must be a string. Lists (sequential integer keys starting at 0, per
 *    `array_is_list()`) encode as a JSON array, in their existing order — order within a list is
 *    caller-controlled, meaningful, and never re-sorted. Associative arrays encode as a JSON
 *    object with keys sorted per rule 1.
 * 7. `null` values are preserved and encoded as JSON `null`, never dropped. Omitting a key and
 *    setting it to `null` are different facts; only the caller's DTO decides which one applies,
 *    and this class must not silently collapse that distinction.
 */
final class CanonicalJson
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function encode(array $data): string
    {
        $normalized = self::normalize($data);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException(
                'CanonicalJson forbids floats: use integers or decimal strings so encoding is byte-stable.',
            );
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'CanonicalJson requires string keys for associative data; got key of type ' . gettype($key) . '.',
                );
            }

            $normalized[$key] = self::normalize($item);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }
}
