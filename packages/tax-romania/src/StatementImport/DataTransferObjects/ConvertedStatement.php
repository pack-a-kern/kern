<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\DataTransferObjects;

/**
 * A statement PDF as the provider converted it to CSV, or the reason it was not.
 *
 * `pipeline`, `milliseconds` and `warning` are what the converter reported about its own work — kept
 * so a table that came out wrong can be traced to how it was made. None of them is the file's
 * content; `warning` is the converter's sentence about what it could not parse.
 */
final readonly class ConvertedStatement
{
    /** The converter could not be reached, or refused the request. */
    public const string FAILED = 'CONVERSION_FAILED';

    /** The converter answered, but with no CSV in it. */
    public const string EMPTY = 'CONVERSION_EMPTY';

    private function __construct(
        public ?string $csv,
        public ?string $failure,
        public ?string $pipeline = null,
        public ?int $milliseconds = null,
        public ?string $warning = null,
    ) {
    }

    public static function of(string $csv, ?string $pipeline, ?int $milliseconds, ?string $warning): self
    {
        return new self($csv, null, $pipeline, $milliseconds, $warning);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }

    /**
     * What the screen and the audit trail are told about how the table was made.
     *
     * @return array{pipeline: string|null, ms: int|null, warning: string|null}
     */
    public function report(): array
    {
        return ['pipeline' => $this->pipeline, 'ms' => $this->milliseconds, 'warning' => $this->warning];
    }
}
