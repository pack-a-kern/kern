<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kern\TaxRomania\Fx\Models\FxRate;

/**
 * The year given to `kern:fx:backfill` is where to start, not the only year fetched.
 *
 * An operator asking to backfill 2023 is saying the rates from 2023 are missing or wrong, and the years after
 * it are missing or wrong for the same reason — a gap found in 2023 was never a gap in 2023 alone. Fetching
 * only the year typed left them to type each following year and to notice when they had finished.
 *
 * Driven through `Http::fake`, as the action's own test is: the loop is what is under test, and faking the
 * action instead would assert that the command calls a thing rather than that the years get fetched.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    Storage::fake('s3');

    config([
        'sources.entries.BNR.allowed_hosts' => ['bnr-fixture.test'],
        'sources.entries.BNR.endpoints.yearly_xml_template' => 'https://bnr-fixture.test/years/{year}.xml',
    ]);
});

/** @return list<int> the years actually asked for, in order */
function yearsFetched(): array
{
    $years = [];

    foreach (Http::recorded() as [$request]) {
        if (preg_match('#/years/(\d{4})\.xml$#', (string) $request->url(), $matches) === 1) {
            $years[] = (int) $matches[1];
        }
    }

    return $years;
}

it('fetches every year from the one given to the present', function (): void {
    $current = (int) now('UTC')->format('Y');
    Http::fake([
        'https://bnr-fixture.test/years/*' => Http::response(
            yearlyFixture('synthetic-yearly.xml'),
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    expect(Artisan::call('kern:fx:backfill', ['year' => $current - 2]))->toBe(0)
        ->and(yearsFetched())->toBe(range($current - 2, $current));
});

it('fetches one year when that year is the present', function (): void {
    $current = (int) now('UTC')->format('Y');
    Http::fake([
        'https://bnr-fixture.test/years/*' => Http::response(
            yearlyFixture('synthetic-yearly.xml'),
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    Artisan::call('kern:fx:backfill', ['year' => $current]);

    expect(yearsFetched())->toBe([$current]);
});

it('stops at the year that failed, keeping what was already written', function (): void {
    /*
     * A failure partway does not undo the years before it. The years already fetched keep their rates, and
     * the message names the year that broke — the difference between a retry and a reread of the logs.
     */
    $current = (int) now('UTC')->format('Y');
    Http::fake([
        "https://bnr-fixture.test/years/{$current}.xml" => Http::response('', 503),
        'https://bnr-fixture.test/years/*' => Http::response(
            yearlyFixture('synthetic-yearly.xml'),
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $exit = Artisan::call('kern:fx:backfill', ['year' => $current - 1]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain((string) $current)
        // The year before it was written and stays written.
        ->and(FxRate::query()->count())->toBeGreaterThan(0);
});

it('refuses a year that is not a number', function (): void {
    expect(Artisan::call('kern:fx:backfill', ['year' => 'last']))->toBe(1);
});
