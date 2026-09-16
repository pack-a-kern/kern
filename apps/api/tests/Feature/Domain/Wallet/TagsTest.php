<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\TagsTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\MembershipRole;
use App\Domain\Subjects\Models\SubjectMembership;
use App\Domain\Subjects\Models\TaxSubject;
use App\Domain\Wallet\Enums\AccountKind;
use App\Domain\Wallet\Enums\RecordSource;
use App\Domain\Wallet\Models\FinancialAccount;
use App\Domain\Wallet\Models\Tag;
use App\Support\KeyManagement\SubjectDekService;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;
use Tests\TestCase;

/**
 * Tags — a person's own labels for their spending, beside the tax category.
 *
 * The behaviour worth holding: one spelling per taxpayer however it is typed, a rename that refuses
 * to become a silent merge, a merge that moves everything, and a delete that costs a label and
 * nothing else.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

/** @return array{0: User, 1: string, 2: string} the user, the subject, and a source to record into */
function taggedSubject(): array
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

    $account = FinancialAccount::create([
        'tax_subject_id' => $subject->id,
        'kind' => AccountKind::GENERAL,
        'display_name_ciphertext' => null,
        'currency_code' => 'RON',
        'source' => RecordSource::MANUAL,
    ]);

    return [$user, $subject->id, $account->id];
}

/** @param list<string> $tags */
function record(TestCase $case, User $user, string $subjectId, string $accountId, int $amount, array $tags, string $description = 'Cumpărături'): string
{
    $id = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/wallet/transactions", [
        'account_id' => $accountId,
        'amount_minor' => $amount,
        'currency_code' => 'RON',
        'booked_at' => '2026-03-05',
        'description' => $description,
        'tags' => $tags,
    ])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
}

it('creates a tag by typing it, and never the same one twice', function (): void {
    [$user, $subjectId, $accountId] = taggedSubject();

    // The same label in three spellings: a person typing quickly, and a model proposing tidily.
    record($this, $user, $subjectId, $accountId, -12_000, ['Alimente']);
    record($this, $user, $subjectId, $accountId, -8_000, ['  alimente ']);
    record($this, $user, $subjectId, $accountId, -5_000, ['ALIMENTE']);

    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/tags")->assertOk();

    expect($response->json('tags'))->toHaveCount(1)
        ->and($response->json('tags.0.name'))->toBe('alimente');
});

it('shows a transaction under its tags, and filters the list by one', function (): void {
    [$user, $subjectId, $accountId] = taggedSubject();

    record($this, $user, $subjectId, $accountId, -12_000, ['alimente'], 'Kaufland');
    record($this, $user, $subjectId, $accountId, -20_000, ['combustibil'], 'OMV');

    /** @var string $tagId */
    $tagId = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/tags")
        ->json('tags.0.id');

    $all = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk();

    expect($all->json('total'))->toBe(2)
        ->and($all->json('transactions.0.tags'))->toHaveCount(1);

    // Filtered on the server: an imported statement is more rows than any window would hold, so a
    // filter applied to what came back would answer about the window rather than the ledger.
    $filtered = $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions?tag_id={$tagId}")
        ->assertOk();

    expect($filtered->json('total'))->toBe(1);
});

it('refuses a rename onto a name that already exists, because that is a merge', function (): void {
    [$user, $subjectId, $accountId] = taggedSubject();

    record($this, $user, $subjectId, $accountId, -12_000, ['alimente']);
    record($this, $user, $subjectId, $accountId, -20_000, ['combustibil']);

    /** @var Tag $tag */
    $tag = Tag::query()->get()->first(static fn (Tag $candidate): bool => (string) $candidate->name_ciphertext === 'alimente');
    $tagId = $tag->id;

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/tags/{$tagId}", ['name' => 'combustibil'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'TAG_EXISTS');

    // And a plain rename still works, normalised the same way as a typed one.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/wallet/tags/{$tagId}", ['name' => '  Mâncare '])
        ->assertOk()
        ->assertJsonPath('name', 'mâncare');
});

it('moves every transaction onto the surviving tag when two are merged', function (): void {
    [$user, $subjectId, $accountId] = taggedSubject();

    record($this, $user, $subjectId, $accountId, -12_000, ['restaurante']);
    // One row carries both, which is exactly where a naive move would collide on the pivot's key.
    record($this, $user, $subjectId, $accountId, -9_000, ['restaurante', 'mancare in oras']);
    record($this, $user, $subjectId, $accountId, -7_000, ['mancare in oras']);

    $tags = Tag::query()->get();
    /** @var Tag $from */
    $from = $tags->first(static fn (Tag $tag): bool => (string) $tag->name_ciphertext === 'mancare in oras');
    /** @var Tag $into */
    $into = $tags->first(static fn (Tag $tag): bool => (string) $tag->name_ciphertext === 'restaurante');

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/wallet/tags/{$from->id}/merge", ['into_tag_id' => $into->id])
        ->assertOk();

    expect(Tag::query()->count())->toBe(1)
        ->and(DB::table('transaction_tags')->where('tag_id', $into->id)->count())->toBe(3);
});

it('loses the label and nothing else when a tag is deleted', function (): void {
    [$user, $subjectId, $accountId] = taggedSubject();

    $transactionId = record($this, $user, $subjectId, $accountId, -12_000, ['alimente']);

    /** @var string $tagId */
    $tagId = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/tags")->json('tags.0.id');

    $this->actingAs($user)->deleteJson("/api/v1/subjects/{$subjectId}/wallet/tags/{$tagId}")->assertNoContent();

    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/transactions")->assertOk();

    expect(Tag::query()->count())->toBe(0)
        ->and($response->json('total'))->toBe(1)
        ->and($response->json('transactions.0.id'))->toBe($transactionId)
        ->and($response->json('transactions.0.amount_minor'))->toBe(-12_000)
        ->and($response->json('transactions.0.tags'))->toBe([]);
});

it('keeps one taxpayer tags away from another', function (): void {
    [$user, $subjectId, $accountId] = taggedSubject();
    [$otherUser, $otherSubjectId, $otherAccountId] = taggedSubject();

    record($this, $user, $subjectId, $accountId, -12_000, ['alimente']);
    record($this, $otherUser, $otherSubjectId, $otherAccountId, -12_000, ['combustibil']);

    $response = $this->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/wallet/tags")->assertOk();

    expect($response->json('tags'))->toHaveCount(1)
        ->and($response->json('tags.0.name'))->toBe('alimente');
});
