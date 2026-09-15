<?php

declare(strict_types=1);

namespace App\Domain\Documents\ExternalAi;

use App\Domain\Documents\Enums\DocumentType;
use App\Domain\Documents\Parsing\ExtractionField;
use App\Domain\Documents\Parsing\ExtractionFieldKind;
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
     * scalar is typed `string`: amounts are decimal strings and dates are ISO strings throughout
     * this codebase, never numbers, because a float cannot hold money.
     *
     * Groups and lists are described as far down as the schema knows, so a model asked for invoice
     * lines is told exactly what a line contains rather than being left to invent the keys.
     *
     * ## Every property is required, and optional ones are nullable
     *
     * That is not this codebase's idea of optional — it is what `strict: true` structured output
     * means. A schema that lists twenty properties and requires three is *invalid* under strict
     * mode, and a provider handed one either refuses the request or, as ours evidently did, ignores
     * `response_format` altogether. The schema we believed was constraining every reply was
     * constraining nothing.
     *
     * So an optional field is expressed the way the format expresses it: present in `required`,
     * and typed `["string", "null"]` so the model can answer "not on the document" explicitly. The
     * validator drops nulls on the way back in, which is exactly what it already did for absent
     * keys — {@see self::walk()}.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(?DocumentType $type): array
    {
        // No type yet — the ordinary case for a freshly uploaded document. Ask for everything and
        // for the kind, and hold the reply to that kind's own rules afterwards.
        $schema = $type === null ? ExtractionSchema::union() : ExtractionSchema::for($type);

        return $this->objectSchema($schema->fields);
    }

    /**
     * @param  list<ExtractionField>  $fields
     * @return array<string, mixed>
     */
    private function objectSchema(array $fields): array
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            $properties[$field->name] = $this->propertySchema($field);
            // Every one of them. See the class docblock: under `strict: true` this list is not
            // "which fields matter", it is "which keys the reply will contain" — and a partial one
            // makes the whole schema invalid, so nothing is enforced at all.
            $required[] = $field->name;
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * One property, made nullable where this codebase calls the field optional.
     *
     * `["string", "null"]` rather than omission from `required`: that is how strict structured
     * output spells "may be absent", and it also gives the model somewhere to put "I could not read
     * this" other than inventing a value.
     *
     * @return array<string, mixed>
     */
    private function propertySchema(ExtractionField $field): array
    {
        $schema = match ($field->kind) {
            ExtractionFieldKind::SCALAR => ['type' => 'string'],
            ExtractionFieldKind::OBJECT => $this->objectSchema($field->children),
            ExtractionFieldKind::LIST => [
                'type' => 'array',
                'items' => $this->objectSchema($field->children),
            ],
        };

        if ($field->required || $field->name === ExtractionSchema::FIELD_CONFIDENCE) {
            // Confidence is the one field asked for without being load-bearing. Left nullable, a
            // model answers null — it is not on the document, and a nullable field is an invitation
            // to say so — and every reading arrives with nothing to show. Typed non-nullable it has
            // to produce a number, which is all a self-assessment ever was.
            //
            // Its absence still never fails a reading: `ExtractionSchema` keeps it optional, so
            // `missing()` does not look for it. A reading of a real document must not be thrown
            // away because a model declined to grade itself.
            return $schema;
        }

        $type = $schema['type'];
        $schema['type'] = [is_string($type) ? $type : 'string', 'null'];

        return $schema;
    }

    /**
     * The reply, and what kind of document it turned out to describe.
     *
     * When `$type` is null the model was asked to classify, so its answer decides which schema the
     * reply is held to — the kind and the fields come from one reading, rather than from a person
     * guessing before anyone had read the document.
     *
     * **A label never fails a reading.** A `document_kind` nobody recognises falls back to `OTHER`,
     * which is a real schema with real rules, not an absence of them. This used to reject the reply
     * outright, on the reasoning that an unknown kind meant validating against nothing — but every
     * type now carries layer one, so the choice is between holding the reply to the rules every
     * document shares and discarding a read of the issuer, the recipient and every line item over a
     * word the model chose for it.
     *
     * @param  array<array-key, mixed>  $reply
     */
    public function validate(?DocumentType $type, array $reply): ?ValidatedExtraction
    {
        $resolved = $type ?? $this->classified($reply);
        $schema = ExtractionSchema::for($resolved);

        $fields = $this->walk($schema->fields, $reply);

        return $schema->missing($fields) === [] ? new ValidatedExtraction($resolved, $fields) : null;
    }

    /**
     * What the model said this document is, or `OTHER` when it said nothing we recognise.
     *
     * @param  array<array-key, mixed>  $reply
     */
    private function classified(array $reply): DocumentType
    {
        $kind = $reply[ExtractionSchema::FIELD_DOCUMENT_KIND] ?? null;

        return (is_string($kind) ? DocumentType::tryFrom(strtoupper(trim($kind))) : null) ?? DocumentType::OTHER;
    }

    /**
     * One level of the reply, checked against the fields that describe it.
     *
     * Anything the schema does not name is dropped rather than stored: a key nobody downstream
     * understands would appear on the review screen as though it meant something.
     *
     * @param  list<ExtractionField>  $fields
     * @param  array<array-key, mixed>  $reply
     * @return array<string, mixed>
     */
    private function walk(array $fields, array $reply): array
    {
        $kept = [];

        foreach ($fields as $field) {
            $value = $reply[$field->name] ?? null;

            if ($value === null) {
                continue;
            }

            $normalised = match ($field->kind) {
                ExtractionFieldKind::SCALAR => $this->scalar($value),
                ExtractionFieldKind::OBJECT => $this->group($field, $value),
                ExtractionFieldKind::LIST => $this->entries($field, $value),
            };

            if ($normalised !== null) {
                $kept[$field->name] = $normalised;
            }
        }

        return $kept;
    }

    /**
     * One group, or null when it is not one or does not satisfy its own required fields.
     *
     * A group that is present must be complete: an `issuer` block naming nobody is worse than no
     * issuer block, because the review screen would show an empty panel as though something had
     * been read.
     *
     * @return array<string, mixed>|null
     */
    private function group(ExtractionField $field, mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $group = $this->walk($field->children, $value);

        foreach ($field->children as $child) {
            if ($child->required && ! isset($group[$child->name])) {
                return null;
            }
        }

        return $group === [] ? null : $group;
    }

    /**
     * A list's entries, dropping any that do not stand on their own.
     *
     * Dropped rather than failing the whole reading: one unreadable line on a forty-line invoice
     * should not discard the other thirty-nine, and a reviewer can see that the lines do not sum to
     * the total. An entry that keeps nothing at all is not a line and is left out.
     *
     * @return list<array<string, mixed>>|null
     */
    private function entries(ExtractionField $field, mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $entries = [];

        foreach ($value as $entry) {
            $group = $this->group($field, $entry);

            if ($group !== null) {
                $entries[] = $group;
            }
        }

        return $entries === [] ? null : $entries;
    }

    /**
     * One value, normalised — never repaired.
     *
     * A trimmed string is the same value; a "1.234,56" turned into 1234.56 would be this class
     * deciding what a document says, which is the parser's job where the rules are explicit and
     * testable. Here a malformed amount is simply kept as written and rejected downstream.
     */
    private function scalar(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        // A number where a decimal string was asked for: kept, because the *value* is unambiguous
        // even though the format was not. Rendered without exponent notation so a large amount does
        // not become "1.0E+6".
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        }

        // Objects and lists where one value was asked for: the model has not answered the question,
        // and flattening one would invent a value it never gave.
        return null;
    }
}
