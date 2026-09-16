<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\DataTransferObjects;

/**
 * The transactions read out of a statement, and what became of every line that is not one.
 *
 * ## Why the counts travel with the rows
 *
 * Because "12 522 lines became 1 700 transactions" is either correct or alarming, and a person can
 * only tell which if they are shown the arithmetic. Folded lines are the detail lines gathered into
 * the transaction above them; skipped lines are headings and footers the form named. The three
 * counts plus the transactions add up to the lines read, so a line that quietly disappeared shows up
 * as a number that does not balance rather than as a missing payment nobody looks for.
 */
final readonly class BuiltStatementRows
{
    /**
     * @param  list<array{row_number: int, booked_at: ?string, amount_minor: ?int, currency_code: ?string, text: string, counterparty: string, reference: string}>  $rows
     */
    public function __construct(
        public array $rows,
        public int $linesRead,
        public int $linesFolded,
        public int $linesSkipped,
    ) {
    }

    /** @return array<string, int> what the screen shows beside the import */
    public function counts(): array
    {
        return [
            'lines_read' => $this->linesRead,
            'transactions' => count($this->rows),
            'lines_folded' => $this->linesFolded,
            'lines_skipped' => $this->linesSkipped,
        ];
    }
}
