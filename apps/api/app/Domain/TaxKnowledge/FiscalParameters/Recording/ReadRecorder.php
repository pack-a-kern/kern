<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\FiscalParameters\Recording;

use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterReadOutcome;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterRead;
use Illuminate\Support\Carbon;

/**
 * What a year's reading found nothing for, kept so that nothing reads as an empty space.
 *
 * One row per value and year, replaced by the next reading. A value proposed for that year removes
 * it, because the question it answers — why is there nothing here — no longer has an answer.
 */
final readonly class ReadRecorder
{
    public function __construct(private ParameterCatalog $catalog)
    {
    }

    public function record(string $code, int $year, ?ParameterReadOutcome $outcome): void
    {
        $definition = $this->catalog->find($code);

        if ($definition === null) {
            return;
        }

        $parameter = FiscalParameter::query()->firstOrCreate(['code' => $code], ['value_type' => $definition->type->value]);

        if ($outcome === null) {
            FiscalParameterRead::query()->where('fiscal_parameter_id', $parameter->id)->where('year', $year)->delete();

            return;
        }

        FiscalParameterRead::query()->updateOrCreate(
            ['fiscal_parameter_id' => $parameter->id, 'year' => $year],
            ['outcome' => $outcome->value, 'read_at' => Carbon::now()],
        );
    }
}
