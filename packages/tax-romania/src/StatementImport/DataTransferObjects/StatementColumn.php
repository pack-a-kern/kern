<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\DataTransferObjects;

/**
 * One column of a statement file, described rather than shown.
 *
 * `shape` is what the reader made of the values — `date DD.MM.YYYY`, `number -1.234,56`,
 * `text ~46 chars`. It is precise enough to recognise a column and describes no payment at all,
 * which is what makes it safe to send to a model that has never seen the statement.
 */
final readonly class StatementColumn
{
    public function __construct(
        public int $index,
        public string $header,
        public string $shape,
        public float $filledRatio,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'header' => $this->header,
            'shape' => $this->shape,
            'filled_ratio' => $this->filledRatio,
        ];
    }
}
