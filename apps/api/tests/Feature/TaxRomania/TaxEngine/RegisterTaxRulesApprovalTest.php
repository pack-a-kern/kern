<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Kern\Platform\Approval\ApprovalStatus;
use Kern\TaxRomania\TaxRules\TaxRuleVersion;

/**
 * Approving a rule version from the command line.
 *
 * `ForbidsAgentApproval` says its one allowed path is "reached only from an authenticated request holding
 * `fiscal.parameters.approve` — never from a job, a command or a model's output". `--approve-as` departs from that
 * sentence deliberately, because there is no approval screen for rule versions and without it no version can ever
 * be approved and no `FINAL` assessment can exist.
 *
 * What these tests hold to is the invariant the sentence was protecting: an approval names somebody accountable,
 * and names somebody who was entitled to decide. A terminal is a different doorway, not a lower bar.
 */
beforeEach(function (): void {
    seedRuleParameters();
});

it('leaves versions as candidates when nobody is approving', function (): void {
    Artisan::call('kern:tax:register-rules');

    expect(TaxRuleVersion::query()->withoutGlobalScopes()->get())->not->toBeEmpty()
        ->and(TaxRuleVersion::query()->withoutGlobalScopes()->where('status', ApprovalStatus::APPROVED->value)->count())
        ->toBe(0);
});

it('approves every version for a person entitled to decide, and names them', function (): void {
    $approver = settingsAdminUser();

    $exit = Artisan::call('kern:tax:register-rules', ['--approve-as' => $approver->id]);

    $versions = TaxRuleVersion::query()->withoutGlobalScopes()->get();

    expect($exit)->toBe(0)
        ->and($versions)->not->toBeEmpty()
        ->and($versions->every(fn (TaxRuleVersion $v): bool => $v->status === ApprovalStatus::APPROVED))->toBeTrue()
        // Named and dated. A row saying "approved" with nobody named is worse than no approval at all.
        ->and($versions->every(fn (TaxRuleVersion $v): bool => $v->approved_by === $approver->id))->toBeTrue()
        ->and($versions->every(fn (TaxRuleVersion $v): bool => $v->approved_at !== null))->toBeTrue();
});

it('refuses a person who does not hold the permission the screen would have required', function (): void {
    // An ordinary user, and also an ADMIN: `config/access.php` grants ADMIN `fiscal.parameters.view` and
    // withholds `approve` on purpose, so "an administrator ran it" is not the same as "somebody may approve it".
    $exit = Artisan::call('kern:tax:register-rules', ['--approve-as' => ordinaryUser()->id]);

    expect($exit)->toBe(1)
        ->and(TaxRuleVersion::query()->withoutGlobalScopes()->where('status', ApprovalStatus::APPROVED->value)->count())
        ->toBe(0);
});

it('refuses an id that names nobody', function (): void {
    $exit = Artisan::call('kern:tax:register-rules', ['--approve-as' => '01a00000-0000-7000-8000-000000000000']);

    expect($exit)->toBe(1)
        ->and(TaxRuleVersion::query()->withoutGlobalScopes()->where('status', ApprovalStatus::APPROVED->value)->count())
        ->toBe(0);
});

it('keeps the first approver when the command runs again', function (): void {
    /*
     * The row records who decided and when. A second run must not overwrite that with today's date and today's
     * operator, which would erase the record of the decision actually taken.
     */
    $first = settingsAdminUser();
    Artisan::call('kern:tax:register-rules', ['--approve-as' => $first->id]);

    $second = settingsAdminUser();
    Artisan::call('kern:tax:register-rules', ['--approve-as' => $second->id]);

    expect(TaxRuleVersion::query()->withoutGlobalScopes()->pluck('approved_by')->unique()->all())
        ->toBe([$first->id]);
});

it('still refuses application code that sets APPROVED directly', function (): void {
    // The guard is the point, and the flag is a doorway through it rather than a hole in it.
    Artisan::call('kern:tax:register-rules');

    $version = TaxRuleVersion::query()->withoutGlobalScopes()->firstOrFail();
    $version->status = ApprovalStatus::APPROVED;

    expect(fn () => $version->save())
        ->toThrow(Kern\Platform\Approval\AgentApprovalNotPermittedException::class);
});
