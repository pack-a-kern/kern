<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\DataTransferObjects;

/**
 * Where one field of a transaction is found: a column, a label beneath it, or a fixed code.
 *
 * The three cannot be collapsed into one another. A column is a position in the row; a label is a
 * prefix on a detail line (`Tranzactie la:`); a code is a value the file never writes at all, which
 * is how a statement in one currency states its currency. A plain string would be ambiguous between
 * all three, which is why the form always carries the kind beside the value.
 */
final readonly class StatementField
{
    public const string INDEX = 'index';

    public const string LABEL = 'label';

    public const string CODE = 'code';

    private function __construct(
        public string $type,
        public int|string $value,
    ) {
    }

    public static function index(int $column): self
    {
        return new self(self::INDEX, $column);
    }

    public static function label(string $label): self
    {
        return new self(self::LABEL, $label);
    }

    public static function code(string $code): self
    {
        return new self(self::CODE, $code);
    }

    /** Null for anything that is not one of the three kinds — a guess is not a field. */
    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $type = is_string($raw['type'] ?? null) ? $raw['type'] : '';
        $value = $raw['value'] ?? null;

        return match (true) {
            $type === self::INDEX && is_numeric($value) => self::index((int) $value),
            $type === self::LABEL && is_string($value) && trim($value) !== '' => self::label(trim($value)),
            $type === self::CODE && is_string($value) && trim($value) !== '' => self::code(trim($value)),
            default => null,
        };
    }

    /**
     * A field's places, in the order they are tried.
     *
     * One place arrives as an object and several as a list, because a form written when every field
     * had exactly one answer is still stored that way — and a mapping saved a month ago must keep
     * reading the same statement the same way.
     *
     * @return list<self>
     */
    public static function chain(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $places = array_is_list($raw) ? $raw : [$raw];

        return array_values(array_filter(array_map(self::fromArray(...), $places)));
    }

    /**
     * What a chain is written back as: one place stays an object, several become a list.
     *
     * The narrower shape is kept for the common case so that a form nobody has added a place to
     * looks exactly as it always did, in the database and in a diff.
     *
     * @param  list<self>  $chain
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public static function chainToArray(array $chain): array|null
    {
        return match (count($chain)) {
            0 => null,
            1 => $chain[0]->toArray(),
            default => array_map(static fn (self $field): array => $field->toArray(), $chain),
        };
    }

    /** The column number a field names, or null when it names something else. */
    public static function indexIn(mixed $raw): ?int
    {
        $field = self::fromArray($raw);

        return $field !== null && $field->type === self::INDEX ? (int) $field->value : null;
    }

    public function isIndex(): bool
    {
        return $this->type === self::INDEX;
    }

    public function isLabel(): bool
    {
        return $this->type === self::LABEL;
    }

    public function column(): ?int
    {
        return $this->isIndex() ? (int) $this->value : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
