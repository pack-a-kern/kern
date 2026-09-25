<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\DataTransferObjects;

use Illuminate\Support\Facades\Config;

/**
 * One argument an {@see \Kern\TaxRomania\Operations\Enums\OperationTask} accepts, in a form the run
 * dialog can render and the controller can validate from the same declaration.
 *
 * Every type here is *constrained*, and that is the property that matters — there is no free-text
 * case:
 *
 *  - `integer` is bounded by a declared range, and may be optional;
 *  - `choice` is a closed set of values;
 *  - `date` is a calendar date, `YYYY-MM-DD`, and may be left out.
 *
 * `commandKey` exists because an artisan option is addressed as `--type` while a form field cannot
 * be. The screen and the JSON use `name`; {@see \Kern\TaxRomania\Operations\Jobs\RunOperationTask} maps
 * to `commandKey` when it calls the command.
 */
final readonly class TaskArgument
{
    public const string TYPE_INTEGER = 'integer';
    public const string TYPE_CHOICE = 'choice';
    public const string TYPE_DATE = 'date';

    /**
     * @param  list<string>  $options
     */
    private function __construct(
        public string $name,
        public string $commandKey,
        public string $type,
        public ?int $min = null,
        public ?int $max = null,
        public array $options = [],
        public bool $required = true,
    ) {
    }

    /**
     * A fiscal year. The lower bound is `config('operations.fx_backfill.min_year')` — BNR's yearly
     * archives start there — and the upper bound is the current year, resolved when the argument is
     * described rather than pinned, so this does not stop being right in January.
     */
    public static function year(string $name): self
    {
        $min = Config::get('operations.fx_backfill.min_year');

        return new self(
            name: $name,
            commandKey: $name,
            type: self::TYPE_INTEGER,
            min: is_int($min) ? $min : 2005,
            max: (int) date('Y'),
        );
    }

    /** A whole number within a range — an act's number, a year, a portal id. */
    public static function integer(string $name, string $commandKey, int $min, int $max, bool $required = true): self
    {
        return new self(name: $name, commandKey: $commandKey, type: self::TYPE_INTEGER, min: $min, max: $max, required: $required);
    }

    /** A date, `YYYY-MM-DD`, which the operator may leave empty. */
    public static function date(string $name, string $commandKey): self
    {
        return new self(name: $name, commandKey: $commandKey, type: self::TYPE_DATE, required: false);
    }

    /**
     * @param  list<string>  $options
     */
    public static function choice(string $name, string $commandKey, array $options): self
    {
        return new self(name: $name, commandKey: $commandKey, type: self::TYPE_CHOICE, options: $options);
    }


    /**
     * The legal-act categories `config/operations.php` offers. Read from config rather than written
     * here so adding one is an operator's edit, not a code change — the database column it feeds is
     * an unconstrained string, so nothing downstream needs this list to be closed.
     */
    public static function documentType(string $name, string $commandKey): self
    {
        $types = Config::get('operations.legislation.document_types');

        return self::choice($name, $commandKey, self::strings($types));
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(
            is_array($values) ? $values : [],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }


    /**
     * @return array{name: string, type: string, min: int|null, max: int|null, options: list<string>, required: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'min' => $this->min,
            'max' => $this->max,
            'options' => $this->options,
            'required' => $this->required,
        ];
    }
}
