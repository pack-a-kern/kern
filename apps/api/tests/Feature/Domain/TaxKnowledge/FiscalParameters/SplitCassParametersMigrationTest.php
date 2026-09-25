<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\FiscalParameters\SplitCassParametersMigrationTest;

use App\Domain\FiscalParameters\Models\FiscalParameter;
use App\Domain\FiscalParameters\Models\FiscalParameterVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('moves each CASS value to the rule of the income it applies to, keeping its approval', function (): void {
    bootstrapIdentityCryptoForTests();
    $cap = FiscalParameter::factory()->create(['code' => 'RO.CASS.BASE_CAP_WAGES', 'value_type' => 'INTEGER']);
    $bands = FiscalParameter::factory()->create(['code' => 'RO.CASS.BASE_WAGES', 'value_type' => 'JSON']);
    $old = insertApprovedFiscalParameterVersion($cap->id, FiscalParameterVersion::factory()->create()->source_section_id, ['valid_from' => '2022-01-01', 'value_json' => json_encode(['value' => 12])]);
    $new = insertApprovedFiscalParameterVersion($cap->id, FiscalParameterVersion::factory()->create()->source_section_id, ['valid_from' => '2024-01-01', 'value_json' => json_encode(['value' => 60])]);

    $migration = require database_path('migrations/2026_09_22_600000_split_cass_parameters_by_income.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));
    $migration->up();

    $codeOf = static fn (string $version): mixed => DB::table('fiscal_parameter_versions')
        ->join('fiscal_parameters', 'fiscal_parameters.id', '=', 'fiscal_parameter_versions.fiscal_parameter_id')
        ->where('fiscal_parameter_versions.id', $version)
        ->value('fiscal_parameters.code');

    expect($codeOf($old))->toBe('RO.CASS.ALL_INCOME.CAP_WAGES')
        ->and($codeOf($new))->toBe('RO.CASS.INDEPENDENT.CAP_WAGES')
        ->and(DB::table('fiscal_parameter_versions')->whereIn('id', [$old, $new])->pluck('status')->unique()->all())->toBe(['APPROVED'])
        ->and(FiscalParameter::query()->where('code', 'RO.CASS.BASE_CAP_WAGES')->exists())->toBeFalse()
        ->and($bands->fresh()?->code)->toBe('RO.CASS.OTHER_INCOME.BANDS_WAGES');
});
