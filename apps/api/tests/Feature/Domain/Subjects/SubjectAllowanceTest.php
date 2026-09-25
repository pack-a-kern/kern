<?php

declare(strict_types=1);

use App\Domain\Subjects\Actions\RegisterPfSubject;
use App\Domain\Subjects\DataTransferObjects\RegisterPfSubjectInput;
use App\Domain\Subjects\Exceptions\SubjectLimitReachedException;
use App\Domain\Subjects\Services\SubjectAllowance;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\Contracts\KeyManagementService;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * How many taxpayers one account may own.
 *
 * The limit is enforced in the registration action rather than in the controller, so these exercise
 * the action directly — a cap that only the HTTP route respects is not a cap, and this is the test
 * that would notice if it moved.
 */
beforeEach(function (): void {
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $provider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
});

/** @param list<string> $roles */
function allowanceInput(string $userId, string $cnp, array $roles): RegisterPfSubjectInput
{
    return new RegisterPfSubjectInput(
        registeringUserId: $userId,
        registeringUserRoles: $roles,
        displayName: 'Ion Popescu',
        firstName: 'Ion',
        lastName: 'Popescu',
        cnp: $cnp,
        birthDate: '1990-01-01',
    );
}

it('reads the limit for a role, and the default for one it does not know', function (): void {
    config()->set('subjects.limits.default', 1);
    config()->set('subjects.limits.per_role', ['ACCOUNTANT' => 50]);

    $allowance = app(SubjectAllowance::class);

    expect($allowance->limitFor(['ACCOUNTANT']))->toBe(50)
        ->and($allowance->limitFor(['VIEWER']))->toBe(1)
        ->and($allowance->limitFor([]))->toBe(1);
});

it('takes the most generous role an account holds', function (): void {
    config()->set('subjects.limits.default', 1);
    config()->set('subjects.limits.per_role', ['VIEWER' => 1, 'ACCOUNTANT' => 50]);

    expect(app(SubjectAllowance::class)->limitFor(['VIEWER', 'ACCOUNTANT']))->toBe(50);
});

it('refuses a registration once the account is at its limit', function (): void {
    config()->set('subjects.limits.default', 1);
    config()->set('subjects.limits.per_role', []);

    $user = User::factory()->create();
    $register = app(RegisterPfSubject::class);

    $register(allowanceInput($user->id, '1900101221144', []));

    expect(fn () => $register(allowanceInput($user->id, '2900101221145', [])))
        ->toThrow(SubjectLimitReachedException::class);
});

it('lets a role with a larger allowance carry on', function (): void {
    config()->set('subjects.limits.default', 1);
    config()->set('subjects.limits.per_role', ['ACCOUNTANT' => 3]);

    $user = User::factory()->create();
    $register = app(RegisterPfSubject::class);

    $register(allowanceInput($user->id, '1900101221144', ['ACCOUNTANT']));
    $register(allowanceInput($user->id, '2900101221145', ['ACCOUNTANT']));

    expect(app(SubjectAllowance::class)->ownedBy($user->id))->toBe(2);
});
