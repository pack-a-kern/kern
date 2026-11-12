<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Requests\Concerns;

use Kern\Legislation\DataTransferObjects\LawFilter;

/**
 * The laws screen's filter — of one `type`, one `year`, read in by one `watch` — as both its list and its
 * search take it. A watch is named only by whoever may see the watches, the same people the watches
 * screen is for; anyone else asking by one is refused.
 */
trait FiltersLaws
{
    /** Whether this person may filter by a watch, and be offered the watches to filter by. */
    public function mayWatch(): bool
    {
        return $this->user()?->can('operations.run') === true;
    }

    /** @return array<string, list<string>> */
    protected function lawFilterRules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'max:64'],
            'year' => ['sometimes', 'integer', 'digits:4'],
            'watch' => [$this->mayWatch() ? 'sometimes' : 'prohibited', 'uuid'],
        ];
    }

    public function lawFilter(): LawFilter
    {
        return new LawFilter(
            type: $this->filled('type') ? $this->string('type')->toString() : null,
            year: $this->filled('year') ? $this->integer('year') : null,
            watchId: $this->filled('watch') ? $this->string('watch')->toString() : null,
        );
    }
}
