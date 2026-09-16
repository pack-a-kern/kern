<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\DataTransferObjects;

use App\Domain\StatementImport\Enums\StatementFormat;

/**
 * A statement file as the reader handed it back: a grid of text cells, and a description of each
 * column.
 *
 * Cells stay text. "1.234,56" is not a number until someone has said which character is the decimal
 * separator, and that is the question the column mapping answers.
 */
final readonly class ParsedStatementFile
{
    /**
     * @param  list<StatementColumn>  $columns
     * @param  list<list<string>>  $rows  data rows, headers excluded
     * @param  list<string>  $sheets
     * @param  list<string>  $warnings  closed codes — e.g. PAGES_WITHOUT_TABLE
     * @param  list<StatementLabelGroup>  $labelGroups  empty for one transaction per line
     */
    public function __construct(
        public StatementFormat $format,
        public array $columns,
        public array $rows,
        public ?int $headerRow = null,
        public ?string $sheet = null,
        public array $sheets = [],
        public array $warnings = [],
        public array $labelGroups = [],
    ) {
    }

    /**
     * Every label the file actually contains, in one list.
     *
     * The closed set an answer is checked against: a label that is not in here was invented, and a
     * form built on an invented label reads nothing.
     *
     * @return list<string>
     */
    public function labelNames(): array
    {
        $names = [];

        foreach ($this->labelGroups as $group) {
            foreach ($group->labelNames() as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }
}
