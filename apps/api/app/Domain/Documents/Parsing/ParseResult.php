<?php

declare(strict_types=1);

namespace App\Domain\Documents\Parsing;

/**
 * What one parser made of one document.
 *
 * A parser that recognised nothing returns {@see self::noMatch()} rather than an empty field list:
 * "this is not my layout" and "this is my layout and it was empty" are different, and only the
 * second is worth showing a person.
 */
final readonly class ParseResult
{
    /**
     * @param  list<ParsedField>  $fields
     */
    private function __construct(
        public bool $matched,
        public array $fields,
        public string $parserName,
        public string $parserVersion,
    ) {
    }

    /**
     * @param  list<ParsedField>  $fields
     */
    public static function matched(string $parserName, string $parserVersion, array $fields): self
    {
        return new self(true, $fields, $parserName, $parserVersion);
    }

    public static function noMatch(): self
    {
        return new self(false, [], '', '');
    }

    /** The mean of the field confidences, or zero — what the extraction row records overall. */
    public function confidence(): float
    {
        if ($this->fields === []) {
            return 0.0;
        }

        $total = array_sum(array_map(static fn (ParsedField $field): float => $field->confidence, $this->fields));

        return $total / count($this->fields);
    }

    /** @return array<string, string> */
    public function toFieldMap(): array
    {
        $map = [];

        foreach ($this->fields as $field) {
            $map[$field->name] = $field->value;
        }

        return $map;
    }
}
