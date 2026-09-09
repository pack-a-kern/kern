<?php

declare(strict_types=1);

namespace App\Domain\Operations\DataTransferObjects;

use Illuminate\Support\Facades\Config;

/**
 * One argument an {@see \App\Domain\Operations\Enums\OperationTask} accepts, in a form the run
 * dialog can render and the controller can validate from the same declaration.
 *
 * Every type here is *constrained*, and that is the property that matters — there is no free-text
 * case:
 *
 *  - `integer` is bounded by a declared range;
 *  - `choice` is a closed set of values;
 *  - `url` is checked for `https` and for a host on the chosen source's allowlist, both here at
 *    request time and again by `App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher` on every
 *    redirect hop — so a URL typed into the screen cannot reach a host `config/sources.php` does
 *    not name.
 *
 * `commandKey` exists because an artisan option is addressed as `--type` while a form field cannot
 * be. The screen and the JSON use `name`; {@see \App\Domain\Operations\Jobs\RunOperationTask} maps
 * to `commandKey` when it calls the command.
 */
final readonly class TaskArgument
{
    public const string TYPE_INTEGER = 'integer';
    public const string TYPE_CHOICE = 'choice';
    public const string TYPE_URL = 'url';

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

    /**
     * @param  list<string>  $options
     */
    public static function choice(string $name, string $commandKey, array $options): self
    {
        return new self(name: $name, commandKey: $commandKey, type: self::TYPE_CHOICE, options: $options);
    }

    /** The codes in `config/sources.php` that are currently enabled — the only fetch targets there are. */
    public static function sourceCode(string $name): self
    {
        $entries = Config::get('sources.entries');
        $codes = [];

        if (is_array($entries)) {
            foreach ($entries as $code => $entry) {
                if (is_string($code) && is_array($entry) && ($entry['enabled'] ?? null) === true) {
                    $codes[] = $code;
                }
            }
        }

        return self::choice($name, $name, $codes);
    }

    /**
     * The legal-act categories `config/operations.php` offers. Read from config rather than written
     * here so adding one is an operator's edit, not a code change — the database column it feeds is
     * an unconstrained string, so nothing downstream needs this list to be closed.
     */
    public static function documentType(string $name, string $commandKey): self
    {
        $types = Config::get('operations.legislation.document_types');

        return self::choice($name, $commandKey, array_values(array_filter(
            is_array($types) ? $types : [],
            static fn (mixed $type): bool => is_string($type) && $type !== '',
        )));
    }

    public static function url(string $name): self
    {
        return new self(name: $name, commandKey: $name, type: self::TYPE_URL);
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
