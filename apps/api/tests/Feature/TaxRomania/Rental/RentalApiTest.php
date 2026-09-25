<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Rental\RentalApiTest;

use Illuminate\Support\Facades\DB;
use Kern\Documents\Enums\DocumentStatus;
use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Models\Document;
use Kern\Platform\Identity\Models\User;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\MembershipRole;
use Kern\TaxRomania\Subjects\Models\SubjectMembership;
use Kern\TaxRomania\Subjects\Models\TaxSubject;
use Tests\TestCase;

/**
 * Rentals through the API: what a contract owes each year, and nothing about what was paid.
 *
 * Rental income is set by the contract for each year "indiferent de momentul încasării chiriei"
 * (Codul fiscal, art. 84). So every figure asserted here is computed from terms alone — no
 * transaction exists anywhere in this file, and none needs to.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/** @return array{0: User, 1: string} */
function landlord(): array
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
 * The flat from the sample contract: 700 EUR a month from 19 January 2025, owned outright.
 *
 * @param  array<string, mixed>  $overrides
 */
function openRental(TestCase $case, User $user, string $subjectId, array $overrides = []): string
{
    $id = $case->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/rentals", [
        'property_type' => 'APARTMENT',
        'address' => 'Str. Ajustorului, ap. 20, loc parcare 59',
        'ownership_percent' => '100',
        'tenant_type' => 'PF',
        'tenant_name' => 'Prichindel Georgiana Simona',
        'start_date' => '2025-01-19',
        'currency_code' => 'EUR',
        'rent_minor' => 70000,
        'payment_frequency' => 'MONTHLY',
        ...$overrides,
    ])->assertCreated()->json('id');

    return is_string($id) ? $id : '';
}

/** What the listed rental owes in one year, or null when that year has nothing. */
function owedIn(TestCase $case, User $user, string $subjectId, int $year): ?int
{
    $years = $case->actingAs($user)->getJson("/api/v1/subjects/{$subjectId}/rentals")->assertOk()->json('rentals.0.years');

    foreach (is_array($years) ? $years : [] as $entry) {
        if (is_array($entry) && ($entry['year'] ?? null) === $year) {
            return is_int($entry['share_minor'] ?? null) ? $entry['share_minor'] : null;
        }
    }

    return null;
}

it('works out what the contract owes each year, whatever was paid', function (): void {
    [$user, $subjectId] = landlord();
    openRental($this, $user, $subjectId);

    // Twelve monthly periods fall due in 2025, from the 19th of January to the 19th of December.
    expect(owedIn($this, $user, $subjectId, 2025))->toBe(12 * 70000);
});

it('counts only this taxpayer’s share of a co-owned property', function (): void {
    [$user, $subjectId] = landlord();
    openRental($this, $user, $subjectId, ['ownership_percent' => '50']);

    expect(owedIn($this, $user, $subjectId, 2025))->toBe(12 * 35000);
});

it('owes the new rent from the day it changes, and the old rent before it', function (): void {
    [$user, $subjectId] = landlord();
    $rentalId = openRental($this, $user, $subjectId);

    $this->actingAs($user)->postJson("/api/v1/subjects/{$subjectId}/rentals/{$rentalId}/rent", [
        'effective_from' => '2025-07-19',
        'rent_minor' => 80000,
        'payment_frequency' => 'MONTHLY',
    ])->assertOk();

    // The first half of the year stays at the rent it was owed at; rewriting it would change a year
    // that may already have been declared.
    expect(owedIn($this, $user, $subjectId, 2025))->toBe(6 * 70000 + 6 * 80000);
});

it('stops owing rent when a rental ends', function (): void {
    [$user, $subjectId] = landlord();
    $rentalId = openRental($this, $user, $subjectId);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/rentals/{$rentalId}/end", ['end_date' => '2025-07-19'])
        ->assertOk();

    expect(owedIn($this, $user, $subjectId, 2025))->toBe(6 * 70000)
        ->and(owedIn($this, $user, $subjectId, 2026))->toBeNull();

    // The stored periods follow the terms too — none are left past the end.
    expect(DB::table('rental_accruals')->whereDate('period_start', '>=', '2025-07-19')->count())->toBe(0);

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/rentals/{$rentalId}/end", ['end_date' => '2025-08-19'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'RENTAL_ENDED');
});

it('makes a rental from an accepted contract, once, and never a transaction', function (): void {
    [$user, $subjectId] = landlord();
    $document = Document::factory()->status(DocumentStatus::READY)->create([
        'tax_subject_id' => $subjectId,
        'type' => DocumentType::CONTRACT,
    ]);

    DB::table('document_extractions')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid7(),
        'document_id' => $document->id,
        'extractor_type' => 'EXTERNAL_AI',
        'extractor_version' => 'gateway@1',
        'schema_version' => '1',
        'result_ciphertext' => '{}',
        'confidence_decimal' => '0.9000',
        'status' => 'ACCEPTED',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    // A contract's rent is owed every period for as long as it runs; one transaction for one month
    // would be neither the income nor money that moved.
    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}/transaction", [
            'account_id' => (string) \Illuminate\Support\Str::uuid7(),
            'amount_minor' => 70000,
            'currency_code' => 'EUR',
            'booked_at' => '2025-01-19',
            'description' => 'Chirie',
        ])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'CONTRACT_DOCUMENT');

    openRental($this, $user, $subjectId, ['document_id' => $document->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/subjects/{$subjectId}/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('transaction_import', 'IMPORTED')
        ->assertJsonPath('stage', 'DONE');

    $this->actingAs($user)
        ->postJson("/api/v1/subjects/{$subjectId}/rentals", [
            'property_type' => 'APARTMENT',
            'address' => 'Str. Ajustorului',
            'ownership_percent' => '100',
            'tenant_type' => 'PF',
            'tenant_name' => 'Prichindel Georgiana Simona',
            'start_date' => '2025-01-19',
            'currency_code' => 'EUR',
            'rent_minor' => 70000,
            'payment_frequency' => 'MONTHLY',
            'document_id' => $document->id,
        ])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'IMPORTED');
});

it('refuses to change the rent or the dates through an edit', function (): void {
    [$user, $subjectId] = landlord();
    $rentalId = openRental($this, $user, $subjectId);

    // An edit that quietly rewrote the rent would change every year already owed.
    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/rentals/{$rentalId}", ['rent_minor' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rent_minor']);

    $this->actingAs($user)
        ->patchJson("/api/v1/subjects/{$subjectId}/rentals/{$rentalId}", ['ownership_percent' => '25'])
        ->assertOk();

    expect(owedIn($this, $user, $subjectId, 2025))->toBe(12 * 17500);
});

it('deletes a rental, and the property nothing else is let on', function (): void {
    [$user, $subjectId] = landlord();
    $rentalId = openRental($this, $user, $subjectId);

    $this->actingAs($user)->deleteJson("/api/v1/subjects/{$subjectId}/rentals/{$rentalId}")->assertNoContent();

    expect(DB::table('rental_contracts')->count())->toBe(0)
        ->and(DB::table('properties')->count())->toBe(0)
        ->and(DB::table('rental_accruals')->count())->toBe(0);
});

it('never touches another taxpayer’s rental', function (): void {
    [$owner, $ownerSubject] = landlord();
    [$stranger, $strangerSubject] = landlord();
    $rentalId = openRental($this, $owner, $ownerSubject);

    $this->actingAs($stranger)
        ->deleteJson("/api/v1/subjects/{$strangerSubject}/rentals/{$rentalId}")
        ->assertNotFound();

    expect(DB::table('rental_contracts')->count())->toBe(1);
});
