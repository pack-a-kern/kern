<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Wallet\DepositBankTest;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Database\Factories\BankFactory;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * A deposit is at a bank, and the bank is its name: chosen from the imported list, never typed.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string} */
function depositor(): array
{
    $user = User::factory()->create();
    $subject = TaxSubject::factory()->create();

    SubjectMembership::create([
        'subject_id' => $subject->id,
        'user_id' => $user->id,
        'role' => MembershipRole::OWNER,
        'permissions_json' => [],
        'valid_from' => now('UTC'),
    ]);

    return [$user, $subject->id];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function depositAt(string $bankId, array $overrides = []): array
{
    return [
        'bank_id' => $bankId,
        'kind' => 'TERM_DEPOSIT',
        'currency_code' => 'RON',
        'starts_at' => now('UTC')->toDateString(),
        'principal_minor' => 5000000,
        'maturity_at' => now('UTC')->addMonths(6)->toDateString(),
        'annual_rate' => '6.0000',
        'rate_type' => 'FIXED',
        'interest_method' => 'SIMPLE',
        'payment_frequency' => 'AT_MATURITY',
        ...$overrides,
    ];
}

it('names a deposit after the bank it is opened at', function (): void {
    [$user, $subjectId] = depositor();
    $bank = BankFactory::new()->createOne(['name' => 'Banca Transilvania', 'bic' => 'BTRLRO22XXX']);

    $id = $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", depositAt($bank->id))
        ->assertCreated()
        ->json('id');

    expect(DB::table('deposit_terms')->where('account_id', $id)->value('bank_id'))->toBe($bank->id);

    $accounts = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->assertOk()->json('accounts');
    $deposit = collect(is_array($accounts) ? $accounts : [])->firstWhere('id', $id);

    expect(is_array($deposit) ? $deposit['display_name'] : null)->toBe('Banca Transilvania');
});

it('refuses a typed name, a missing bank, and a bank no longer listed', function (): void {
    [$user, $subjectId] = depositor();
    $bank = BankFactory::new()->createOne();
    $closed = BankFactory::new()->createOne(['active' => false]);
    $url = "/api/v1/subjects/{$subjectId}/wallet/accounts";

    $this->actingAs($user)->postJson($url, depositAt($bank->id, ['display_name' => 'Depozitul meu']))
        ->assertStatus(422)->assertJsonValidationErrors(['display_name']);

    $this->actingAs($user)->postJson($url, depositAt($bank->id, ['bank_id' => null]))
        ->assertStatus(422)->assertJsonValidationErrors(['bank_id']);

    $this->actingAs($user)->postJson($url, depositAt($closed->id))
        ->assertStatus(422)->assertJsonValidationErrors(['bank_id']);
});

it('renames a deposit when its bank changes', function (): void {
    [$user, $subjectId] = depositor();
    $first = BankFactory::new()->createOne(['name' => 'CEC Bank']);
    $second = BankFactory::new()->createOne(['name' => 'ING Bank']);

    $created = $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/accounts", depositAt($first->id))
        ->json('id');
    $id = is_string($created) ? $created : '';

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/accounts/{$id}", ['bank_id' => $second->id])
        ->assertOk();

    $accounts = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/accounts")->json('accounts');
    $deposit = collect(is_array($accounts) ? $accounts : [])->firstWhere('id', $id);

    expect(is_array($deposit) ? $deposit['display_name'] : null)->toBe('ING Bank')
        ->and(DB::table('deposit_terms')->where('account_id', $id)->value('bank_id'))->toBe($second->id);
});

it('offers only the banks still listed, with their codes', function (): void {
    [$user, $subjectId] = depositor();
    BankFactory::new()->createOne(['name' => 'Banca Transilvania', 'bic' => 'BTRLRO22XXX']);
    BankFactory::new()->createOne(['name' => 'Alpha Bank', 'active' => false]);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/banks")
        ->assertOk()
        ->assertJsonCount(1, 'banks')
        ->assertJsonPath('banks.0.name', 'Banca Transilvania')
        ->assertJsonPath('banks.0.code', 'BTRL');
});
