<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\FiscalParameters\DropConfirmedByFormChecksMigrationTest;

use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

it('takes the form check off an approved value and leaves everything else it rests on', function (): void {
    bootstrapIdentityCryptoForTests();
    $wage = FiscalParameter::factory()->create(['code' => 'RO.MIN_WAGE', 'value_type' => 'MONEY']);
    $version = insertApprovedFiscalParameterVersion($wage->id, FiscalParameterVersion::factory()->create()->source_section_id, [
        'valid_from' => '2023-10-01',
        'value_json' => json_encode(['amount_minor' => 330000, 'currency' => 'RON']),
        'evidence' => json_encode([
            'readings' => [['kind' => 'decision', 'quote' => 'la suma de 3.300 lei lunar']],
            'checks' => [
                ['kind' => 'confirmed_by_form', 'year' => 2024, 'form_wage' => 3300],
                ['kind' => 'dated_by_note', 'year' => 2023],
            ],
        ]),
    ]);

    $migration = require database_path('migrations/2026_09_24_100000_drop_confirmed_by_form_checks.php');
    assert($migration instanceof Migration && method_exists($migration, 'up'));
    $migration->up();

    $stored = DB::table('fiscal_parameter_versions')->where('id', $version)->value('evidence');
    $evidence = json_decode(is_string($stored) ? $stored : '[]', true);
    assert(is_array($evidence));

    expect($evidence['checks'])->toBe([['kind' => 'dated_by_note', 'year' => 2023]])
        ->and($evidence['readings'])->toBe([['kind' => 'decision', 'quote' => 'la suma de 3.300 lei lunar']])
        ->and(DB::table('fiscal_parameter_versions')->where('id', $version)->value('status'))->toBe('APPROVED');
});
