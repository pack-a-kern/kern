<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\DataTransferObjects;

/**
 * The `Label:` prefixes found beneath transactions whose amount sat in one particular column.
 *
 * ## Why they are grouped by column rather than listed
 *
 * Because that grouping is what tells the two directions apart without anything here knowing which
 * column means what. In the statement this was built against, blocks whose figure sat in one column
 * carry `Tranzactie la` and `Numar autorizare`; blocks whose figure sat in the other carry
 * `Ordonator` and `Referinta`. A flat list of all four would leave the model to guess which belongs
 * to money out — and it guessed wrong, putting the reference used by 13% of rows on 84% of them.
 *
 * The percentages matter as much as the names: a label on 98% of blocks is that direction's
 * counterparty, and one on 4% is an occasional extra.
 */
final readonly class StatementLabelGroup
{
    /** @param list<array{label: string, seen_in_percent: int}> $labels */
    public function __construct(
        public int $amountColumn,
        public int $blocks,
        public array $labels,
    ) {
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $labels = [];

        foreach (is_array($raw['labels'] ?? null) ? $raw['labels'] : [] as $label) {
            if (! is_array($label) || ! is_string($label['label'] ?? null)) {
                continue;
            }

            $labels[] = [
                'label' => $label['label'],
                'seen_in_percent' => is_numeric($label['seen_in_percent'] ?? null)
                    ? (int) $label['seen_in_percent']
                    : 0,
            ];
        }

        return new self(
            amountColumn: is_numeric($raw['amount_column'] ?? null) ? (int) $raw['amount_column'] : 0,
            blocks: is_numeric($raw['blocks'] ?? null) ? (int) $raw['blocks'] : 0,
            labels: $labels,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'amount_column' => $this->amountColumn,
            'blocks' => $this->blocks,
            'labels' => $this->labels,
        ];
    }

    /** @return list<string> */
    public function labelNames(): array
    {
        return array_map(static fn (array $label): string => $label['label'], $this->labels);
    }
}
