<?php

declare(strict_types=1);

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Kern\TaxRomania\Operations\Repositories\FailedJobRepository;
use Laravel\Horizon\Contracts\JobRepository;
use Mockery;

/**
 * Discarding a failure clears it from wherever it is actually recorded.
 *
 * Two stores hold failures and they disagree. Laravel writes a row per failure to `failed_jobs`; Horizon
 * keeps its own record in Redis, and that is what the queue screen lists — because Horizon is the only thing
 * that knows about jobs which are pending or have succeeded.
 *
 * They part whenever one is cleared without the other: a successful `queue:retry` deletes the database row
 * and leaves Horizon's entry standing. The screen then listed a failure the database had never heard of, and
 * discarding it answered 404 — found and refused, for something plainly on screen.
 */
/**
 * Both stores, standing in for the real ones.
 *
 * Mockery rather than hand-written doubles: `JobRepository` declares 27 methods and this test cares about
 * two of them, so stubbing the rest would be twenty-five lines saying nothing.
 *
 * @return array{0: ArrayObject<int, string>, 1: ArrayObject<int, string>} what each store was asked to drop
 */
function fakeStores(bool $inDatabase, bool $inHorizon): array
{
    $forgotten = new ArrayObject();
    $deleted = new ArrayObject();

    $provider = Mockery::mock(FailedJobProviderInterface::class)->shouldIgnoreMissing();
    $provider->shouldReceive('find')->andReturn($inDatabase ? (object) ['uuid' => 'abc'] : null);
    $provider->shouldReceive('forget')->andReturnUsing(static function (string $id) use ($forgotten): bool {
        $forgotten->append($id);

        return true;
    });

    $horizon = Mockery::mock(JobRepository::class)->shouldIgnoreMissing();
    $horizon->shouldReceive('getJobs')->andReturn(collect($inHorizon ? [(object) ['id' => 'abc']] : []));
    $horizon->shouldReceive('deleteFailed')->andReturnUsing(static function (string $id) use ($deleted): int {
        $deleted->append($id);

        return 1;
    });

    app()->instance(FailedJobProviderInterface::class, $provider);
    app()->instance(JobRepository::class, $horizon);

    return [$forgotten, $deleted];
}

it('clears a failure that only the queue monitor knows about', function (): void {
    // The case that answered 404: on screen, and absent from the database.
    [$forgotten, $deleted] = fakeStores(inDatabase: false, inHorizon: true);

    expect(app(FailedJobRepository::class)->exists('abc'))->toBeTrue();

    app(FailedJobRepository::class)->forget('abc');

    expect($forgotten->getArrayCopy())->toBe([])->and($deleted->getArrayCopy())->toBe(['abc']);
});

it('clears both when both hold it', function (): void {
    [$forgotten, $deleted] = fakeStores(inDatabase: true, inHorizon: true);

    app(FailedJobRepository::class)->forget('abc');

    expect($forgotten->getArrayCopy())->toBe(['abc'])->and($deleted->getArrayCopy())->toBe(['abc']);
});

it('says a monitor-only failure cannot be re-run', function (): void {
    /*
     * `queue:retry` rebuilds the job from the database row. Horizon's record describes what happened; the
     * payload is not in it, so offering to retry would be offering something that cannot work.
     */
    fakeStores(inDatabase: false, inHorizon: true);

    expect(app(FailedJobRepository::class)->isRetryable('abc'))->toBeFalse();
});

it('says a stored failure can be', function (): void {
    fakeStores(inDatabase: true, inHorizon: false);

    expect(app(FailedJobRepository::class)->isRetryable('abc'))->toBeTrue();
});

it('is missing only when neither store has it', function (): void {
    fakeStores(inDatabase: false, inHorizon: false);

    expect(app(FailedJobRepository::class)->exists('abc'))->toBeFalse();
});
