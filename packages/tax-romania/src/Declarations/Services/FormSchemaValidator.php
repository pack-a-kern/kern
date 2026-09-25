<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Services;

use Kern\TaxRomania\Declarations\Exceptions\InvalidFormSchemaException;

/**
 * Validates that `form_versions.schema_json` is *well-formed* — never that it is fiscally
 * correct, which this module has no basis to judge (`docs/AGENTS.md` rule 3: no field name or box
 * number in this codebase has been verified against a real ANAF specification).
 *
 * Expected shape, documented once here (see the `form_versions` migration's own docblock for the
 * same convention):
 *
 * ```json
 * {
 *   "sections": [
 *     {
 *       "code": "SECTION_CODE",
 *       "order": 1,
 *       "label": "Human-readable label",
 *       "fields": [
 *         {"code": "FIELD_CODE", "order": 1, "label": "Human-readable label", "data_type": "STRING"}
 *       ]
 *     }
 *   ]
 * }
 * ```
 *
 * `data_type` is one of `STRING|DECIMAL|DATE|INTEGER` — a generic, closed set describing how a
 * future mapper would read a field's raw value, not an ANAF-specific concept. Section and field
 * codes must be unique within their own scope (a duplicate code would make "which field is this"
 * ambiguous to any future mapper).
 */
final class FormSchemaValidator
{
    private const array ALLOWED_DATA_TYPES = ['STRING', 'DECIMAL', 'DATE', 'INTEGER'];

    /**
     * @param array<string, mixed> $schema
     */
    public function assertWellFormed(array $schema): void
    {
        if (! array_key_exists('sections', $schema) || ! is_array($schema['sections'])) {
            throw InvalidFormSchemaException::forReason('missing or non-array top-level "sections" key.');
        }

        $sectionCodes = [];

        foreach ($schema['sections'] as $section) {
            if (! is_array($section)) {
                throw InvalidFormSchemaException::forReason('each section must be an object.');
            }

            $code = $this->requireNonEmptyString($section, 'code', 'section');
            $this->requireInt($section, 'order', 'section');
            $this->requireNonEmptyString($section, 'label', 'section');

            if (in_array($code, $sectionCodes, true)) {
                throw InvalidFormSchemaException::forReason("duplicate section code \"{$code}\".");
            }

            $sectionCodes[] = $code;

            if (! array_key_exists('fields', $section) || ! is_array($section['fields'])) {
                throw InvalidFormSchemaException::forReason("section \"{$code}\" is missing a non-array \"fields\" list.");
            }

            $fieldCodes = [];

            foreach ($section['fields'] as $field) {
                if (! is_array($field)) {
                    throw InvalidFormSchemaException::forReason("a field in section \"{$code}\" must be an object.");
                }

                $fieldCode = $this->requireNonEmptyString($field, 'code', 'field');
                $this->requireInt($field, 'order', 'field');
                $this->requireNonEmptyString($field, 'label', 'field');
                $dataType = $this->requireNonEmptyString($field, 'data_type', 'field');

                if (! in_array($dataType, self::ALLOWED_DATA_TYPES, true)) {
                    throw InvalidFormSchemaException::forReason(
                        "field \"{$fieldCode}\" in section \"{$code}\" has unknown data_type \"{$dataType}\".",
                    );
                }

                if (in_array($fieldCode, $fieldCodes, true)) {
                    throw InvalidFormSchemaException::forReason(
                        "duplicate field code \"{$fieldCode}\" in section \"{$code}\".",
                    );
                }

                $fieldCodes[] = $fieldCode;
            }
        }
    }

    /**
     * @param array<array-key, mixed> $subject
     */
    private function requireNonEmptyString(array $subject, string $key, string $label): string
    {
        $value = $subject[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw InvalidFormSchemaException::forReason("{$label} is missing a non-empty string \"{$key}\".");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $subject
     */
    private function requireInt(array $subject, string $key, string $label): void
    {
        if (! array_key_exists($key, $subject) || ! is_int($subject[$key])) {
            throw InvalidFormSchemaException::forReason("{$label} is missing an integer \"{$key}\".");
        }
    }
}
