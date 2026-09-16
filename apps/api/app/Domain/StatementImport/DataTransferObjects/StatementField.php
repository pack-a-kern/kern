<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\DataTransferObjects;

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

    public function labelName(): ?string
    {
        return $this->isLabel() ? (string) $this->value : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
