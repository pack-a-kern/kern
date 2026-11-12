<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\FiscalParameters\FilingDeadlineTest;

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Identity\Models\User;
use Kern\TaxRomania\FiscalParameters\Enums\FiscalParameterValueType;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameter;
use Kern\TaxRomania\FiscalParameters\Models\FiscalParameterVersion;

/**
 * The next Declarația unică, as the sidebar is handed it with the page: the day a person approved from
 * the law, in the year after the income, counted down on Romania's calendar.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/** One year's deadline, as read from the law — approved by a person unless said otherwise. */
function deadline(int $year, int $day, int $month, bool $approved = true): void
{
    $parameter = FiscalParameter::query()->firstOrCreate(
        ['code' => 'RO.INCOME_TAX.FILING_DEADLINE'],
        ['value_type' => FiscalParameterValueType::JSON->value],
    );

    $version = FiscalParameterVersion::factory()->create([
        'fiscal_parameter_id' => $parameter->id,
        'valid_from' => sprintf('%04d-01-01', $year),
        'valid_until' => sprintf('%04d-12-31', $year),
        'value_json' => ['day' => $day, 'month' => $month],
    ]);

    // The way the application approves one: by a person, never by setting the status.
    if ($approved) {
        $version->approveByPerson(User::factory()->create()->id);
    }
}

/** What the sidebar is handed with a page. */
function sharedDeadline(\Tests\TestCase $test, User $user): mixed
{
    return $test->actingAs($user)->get('/dashboard')->assertOk()->inertiaProps('filingDeadline');
}

it('says last year\'s declaration while its day is ahead, and this year\'s once it has passed', function (): void {
    deadline(2025, 25, 5);
    deadline(2026, 25, 5);
    $user = User::factory()->create();

    $this->travelTo('2026-05-20 12:00:00');
    expect(sharedDeadline($this, $user))->toBe(['income_year' => 2025, 'due_on' => '2026-05-25', 'days_left' => 5]);

    $this->travelTo('2026-05-26 12:00:00');
    expect(sharedDeadline($this, $user))->toBe(['income_year' => 2026, 'due_on' => '2027-05-25', 'days_left' => 364]);
});

it('counts the day itself on Romania\'s calendar, not the server\'s', function (): void {
    deadline(2025, 25, 5);

    // 25 May, 01:00 in Bucharest — still the 24th in UTC.
    $this->travelTo('2026-05-24 22:00:00');

    expect(sharedDeadline($this, User::factory()->create()))->toBe(['income_year' => 2025, 'due_on' => '2026-05-25', 'days_left' => 0]);
});

it('says nothing for a day only proposed, or never read', function (): void {
    deadline(2025, 25, 5, approved: false);
    $this->travelTo('2026-03-01 12:00:00');

    expect(sharedDeadline($this, User::factory()->create()))->toBeNull();
});

it('is handed to signed-in people only', function (): void {
    expect($this->get('/login')->assertOk()->inertiaProps('filingDeadline'))->toBeNull();
});
