<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Subjects\BlindIndexLookupTest;

use App\Domain\Identity\Models\User;
use App\Domain\Subjects\Enums\FiscalIdentifierKind;
use App\Domain\Subjects\Models\TaxSubject;
use App\Support\Crypto\BlindIndex\BlindIndexHasher;
use App\Support\Crypto\BlindIndex\ConfigBlindIndexKeyProvider;
use App\Support\KeyManagement\Contracts\KeyManagementService;
use Database\Factories\TaxSubjectFactory;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\InMemoryKeyManagementService;

/**
 * `BLUEPRINT.md` §4.4 blind-index exact-match lookups, for the two fields this module owns:
 * `users.email_blind_index` (login-by-email) and `tax_subjects.fiscal_identifier_blind_index`
 * (duplicate-identifier detection, ADR 0011 — `docs/adr/0011-canonical-fiscal-identifier.md`).
 * Both must find a row despite realistic formatting differences a human would consider "the same
 * value" — `App\Support\Crypto\BlindIndex\Normalizer` is what makes that true, this test proves
 * it end-to-end through this module's own schema/queries.
 *
 * Diacritic folding (`Normalizer::text()`, e.g. "Ștefan"/"Stefan"/"ştefan") is exercised directly
 * by the crypto-core module's own `tests/Unit/Support/Crypto/BlindIndex/NormalizerTest.php` —
 * not repeated here. Email is case/whitespace-only (`Normalizer::email()`), and a CNP is 13 digits
 * with no letters at all (`Normalizer::cnp()` only strips non-digit formatting), so a diacritic
 * variant of either is not a real input a user could type — the "diacritic and formatting
 * variants" this test proves are: email's case-insensitivity, and CNP's tolerance of
 * spaces/dashes some data-entry UIs insert.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    app()->instance(KeyManagementService::class, new InMemoryKeyManagementService());

    $provider = new ConfigBlindIndexKeyProvider(
        json_encode(['1' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR),
        1,
    );
    app()->instance(BlindIndexHasher::class, new BlindIndexHasher($provider));
});

it('finds a user by email despite case and surrounding-whitespace differences', function (): void {
    User::factory()->withEmail('Ion.Popescu@Example.COM')->create();

    $found = User::query()->whereEmail('  ion.popescu@example.com  ')->first();

    expect($found)->not->toBeNull();
});

it('does not find a user for a genuinely different email', function (): void {
    User::factory()->withEmail('ion.popescu@example.com')->create();

    expect(User::query()->whereEmail('alt.popescu@example.com')->first())->toBeNull();
});

it('detects a duplicate CNP on the canonical tax_subjects column despite spaces and dashes some data-entry UIs insert', function (): void {
    TaxSubjectFactory::new()
        ->withFiscalIdentifier(FiscalIdentifierKind::CNP, '1900101221144')
        ->create();

    $duplicateIndex = app(BlindIndexHasher::class)
        ->compute(FiscalIdentifierKind::CNP->keyedValue('1-900101-221 144'));

    expect(TaxSubject::query()->where('fiscal_identifier_blind_index', $duplicateIndex)->exists())->toBeTrue();
});

it('does not flag a genuinely different CNP as a duplicate', function (): void {
    TaxSubjectFactory::new()
        ->withFiscalIdentifier(FiscalIdentifierKind::CNP, '1900101221144')
        ->create();

    $otherIndex = app(BlindIndexHasher::class)->compute(FiscalIdentifierKind::CNP->keyedValue('1900101221145'));

    expect(TaxSubject::query()->where('fiscal_identifier_blind_index', $otherIndex)->exists())->toBeFalse();
});

/**
 * ADR 0011's core guarantee: a CNP and an unrelated PFA business identifier that happen to
 * normalise to the identical digit string must not be treated as the same fiscal identifier —
 * `App\Domain\Subjects\Enums\FiscalIdentifierKind::keyedValue()` binds the kind into the hashed
 * input specifically to prevent this.
 */
it('does not collide a CNP and a business identifier that normalise to the same digits', function (): void {
    $sameDigits = '1234567890123';

    TaxSubjectFactory::new()
        ->withFiscalIdentifier(FiscalIdentifierKind::CNP, $sameDigits)
        ->create();

    $cuiBlindIndex = app(BlindIndexHasher::class)->compute(FiscalIdentifierKind::CUI->keyedValue($sameDigits));

    expect(TaxSubject::query()->where('fiscal_identifier_blind_index', $cuiBlindIndex)->exists())->toBeFalse();
});

it('produces different blind indexes for the same raw value under different identifier kinds', function (): void {
    $sameDigits = '1234567890123';

    $cnpIndex = app(BlindIndexHasher::class)->compute(FiscalIdentifierKind::CNP->keyedValue($sameDigits));
    $cuiIndex = app(BlindIndexHasher::class)->compute(FiscalIdentifierKind::CUI->keyedValue($sameDigits));

    expect($cnpIndex)->not->toBe($cuiIndex);
});

it('looks up the correct subject by identifier and reports which kind actually matched', function (): void {
    /** @var TaxSubject $cnpSubject */
    $cnpSubject = TaxSubjectFactory::new()
        ->withFiscalIdentifier(FiscalIdentifierKind::CNP, '1900101221144')
        ->create();

    /** @var TaxSubject $cuiSubject */
    $cuiSubject = TaxSubjectFactory::new()
        ->withFiscalIdentifier(FiscalIdentifierKind::CUI, 'J40/1234/2020')
        ->create();

    $cnpIndex = app(BlindIndexHasher::class)->compute(FiscalIdentifierKind::CNP->keyedValue('1900101221144'));
    $foundByCnp = TaxSubject::query()->where('fiscal_identifier_blind_index', $cnpIndex)->first();

    expect($foundByCnp)->not->toBeNull()
        ->and($foundByCnp?->id)->toBe($cnpSubject->id)
        ->and($foundByCnp?->fiscal_identifier_kind)->toBe(FiscalIdentifierKind::CNP);

    $cuiIndex = app(BlindIndexHasher::class)->compute(FiscalIdentifierKind::CUI->keyedValue('J40/1234/2020'));
    $foundByCui = TaxSubject::query()->where('fiscal_identifier_blind_index', $cuiIndex)->first();

    expect($foundByCui)->not->toBeNull()
        ->and($foundByCui?->id)->toBe($cuiSubject->id)
        ->and($foundByCui?->fiscal_identifier_kind)->toBe(FiscalIdentifierKind::CUI);
});
