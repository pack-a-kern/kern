<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Parsing\ExtractionSchema;

/**
 * Turns `ExtractionSchema` into a JSON schema to ask for, and checks what came back against it.
 *
 * `BLUEPRINT.md` §14.4: **invalid schema is a failure, not a best-effort save.** A reply missing the
 * amount is not a partial receipt — it is a reading that did not work, and storing it would put a
 * half-filled form in front of a person as though a machine had understood the document.
 *
 * The reply is validated whatever the provider was asked for. `response_format` is a request, not a
 * guarantee: a model that ignores it fails silently, Anthropic has no equivalent at all, and a
 * gateway may strip the key. Validating locally is the only part of this that actually holds.
 *
 * Values are normalised, never repaired. A trimmed string is the same value; a "1.234,56" turned
 * into 1234.56 would be this class deciding what a document says, which is the parser's job where
 * the rules are explicit and testable — here a malformed amount is simply rejected.
 */
final readonly class ExtractionSchemaValidator
{
    /**
     * The JSON schema to send with the request.
     *
     * `additionalProperties: false` so a model cannot smuggle extra keys past the schema, and every
     * field is typed `string`: amounts are decimal strings and dates are ISO strings throughout this
     * codebase, never numbers, because a float cannot hold money.
     *
     * @return array<string, mixed>|null  null when the type has no agreed field set
     */
    public function jsonSchema(DocumentType $type): ?array
    {
        $schema = ExtractionSchema::for($type);

        if ($schema === null) {
            return null;
        }

        $properties = [];

        foreach (array_merge($schema->required, $schema->optional) as $field) {
            $properties[$field] = ['type' => 'string'];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $schema->required,
            'additionalProperties' => false,
        ];
    }

    /**
     * The reply as a field map, or null if it does not satisfy the schema.
     *
     * @param  array<array-key, mixed>  $reply
     * @return array<string, string>|null
     */
    public function validate(DocumentType $type, array $reply): ?array
    {
        $schema = ExtractionSchema::for($type);

        if ($schema === null) {
            return null;
        }

        $fields = [];

        foreach ($reply as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            // Scalars only. A model returning an object or a list for `amount` has not answered the
            // question, and flattening it would invent a value it never gave.
            if (is_string($value)) {
                $fields[$name] = trim($value);
            } elseif (is_int($value) || is_float($value)) {
                // A number where a decimal string was asked for: kept, because the *value* is
                // unambiguous even though the format was not. Rendered without exponent notation so
                // a large amount does not become "1.0E+6".
                $fields[$name] = is_float($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : (string) $value;
            }
        }

        // Anything the schema does not name is dropped rather than stored: a key nobody downstream
        // understands would appear on the review screen as though it meant something.
        $fields = $schema->keepKnown(array_filter($fields, static fn (string $value): bool => $value !== ''));

        return $schema->missing($fields) === [] ? $fields : null;
    }
}
