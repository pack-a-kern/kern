<?php

declare(strict_types=1);

namespace Kern\TaxRomania\FiscalParameters\Recording;

use Illuminate\Support\Carbon;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterRead;
use Kern\TaxRomania\FiscalParameters\ParameterCatalog;
use Kern\TaxRomania\FiscalParameters\ParameterReadOutcome;

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
