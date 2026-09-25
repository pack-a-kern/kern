<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Operations\DataTransferObjects;

/**
 * One "is this data current" row: exchange rates, legislation, the retrieval index, the document
 * backlog.
 *
 * `state` is one of `ok`, `warn`, `bad`, `idle` — the same four tones the UI's `Status` component
 * renders, decided here rather than in the browser so the threshold that produced it (from
 * `config('operations.freshness.*')`) stays next to the query that measured it.
 *
 * `detail` names what was counted, in operator terms. Never a taxpayer value: these probes count
 * rows and read dates, and nothing in this DTO can carry a document's contents.
 */
final readonly class FreshnessItem
{
    public function __construct(
        public string $key,
        public string $state,
        public ?string $value,
        public ?string $detail,
    ) {
    }

    /** @return array{key: string, state: string, value: string|null, detail: string|null} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'state' => $this->state, 'value' => $this->value, 'detail' => $this->detail];
    }
}
