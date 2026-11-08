<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Actions;

use Illuminate\Support\Facades\DB;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterRead;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;

/**
 * Every value read for every parameter, whatever was decided about it, gone — so the next read
 * proposes them all afresh from the law.
 *
 * A rejected value is otherwise never proposed again for its period, and an approved one stands
 * until another is approved over it; after a reader is corrected, starting over is the honest way
 * to see what it now reads. The parameters themselves — the list of what a tax needs — stay.
 *
 * Nothing already computed changes: an assessment keeps the figures it was computed with, and
 * names the versions it used by id rather than through a key that would hold them here. A tax
 * computed after this waits for values to be approved again, as one did before the first read.
 */
final readonly class DropFiscalParameterValues
{
    /** @return array{versions: int, reads: int} what went */
    public function __invoke(): array
    {
        return DB::transaction(static fn (): array => [
            'versions' => FiscalParameterVersion::query()->toBase()->delete(),
            'reads' => FiscalParameterRead::query()->toBase()->delete(),
        ]);
    }
}
