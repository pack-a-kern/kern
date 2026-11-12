<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kern\Legislation\DataTransferObjects\PortalActToRead;
use Kern\Legislation\Legislatie\ImportableTypes;
use RuntimeException;

/**
 * The acts chosen from a portal search to be read: each by its type (one the ingest task takes), number and
 * year, and the portal id that picks it out where several share them — at most `LEGISLATION_IMPORT_MAX_ACTS`,
 * none twice.
 */
final class ImportPortalActsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(ImportableTypes $types): array
    {
        return [
            'acts' => ['required', 'array', 'min:1', 'max:' . self::maxActs()],
            'acts.*' => ['required', 'array:type,number,year,portal_id'],
            'acts.*.type' => ['required', 'string', Rule::in($types->keys())],
            'acts.*.number' => ['required', 'integer', 'min:1', 'max:99999'],
            'acts.*.year' => ['required', 'integer', 'min:' . config()->integer('legislation.form.min_year'), 'max:' . date('Y')],
            'acts.*.portal_id' => ['required', 'integer', 'min:1', 'max:99999999', 'distinct'],
        ];
    }

    /** @return list<PortalActToRead> */
    public function acts(): array
    {
        /** @var list<array{type: string, number: int|string, year: int|string, portal_id: int|string}> $acts */
        $acts = $this->validated('acts');

        return array_map(static fn (array $act): PortalActToRead => new PortalActToRead(
            type: $act['type'],
            number: (int) $act['number'],
            year: (int) $act['year'],
            portalId: (string) $act['portal_id'],
        ), $acts);
    }

    /**
     * `legislation.imports.max_acts`, checked: a value missing or malformed fails, naming its variable. Also
     * the screen's, so it offers no more than this takes.
     */
    public static function maxActs(): int
    {
        $max = filter_var(config('legislation.imports.max_acts'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);

        return is_int($max) ? $max : throw new RuntimeException('LEGISLATION_IMPORT_MAX_ACTS must be a whole number, 1 to 1000.');
    }
}
