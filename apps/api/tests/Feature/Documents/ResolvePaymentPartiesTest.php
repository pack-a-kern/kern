<?php

declare(strict_types=1);

namespace Tests\Feature\Documents\ResolvePaymentPartiesTest;

use Kern\Documents\Payments\ResolvePaymentParties;
use Kern\Platform\KeyManagement\SubjectDekService;
use Kern\Platform\Testing\InMemoryDekRepository;
use Kern\Platform\Testing\InMemoryKeyManagementService;
use Kern\TaxRomania\Subjects\Enums\FiscalIdentifierKind;
use Kern\TaxRomania\Subjects\Models\TaxSubject;

/**
 * Which side of a payment the taxpayer is on — decided here, not by the model.
 *
 * The model was asked this and could not answer it: the request named the taxpayer, it failed to
 * find that name on the page, and every taxpayer-relative field fell back to the document's own
 * point of view. A dividend decision paying its associates came back as money OUT with "Alpha Bank"
 * as the counterparty, because the bank was the only other name on the page.
 */
beforeEach(function (): void {
    bootstrapIdentityCryptoForTests();

    $this->app->instance(
        SubjectDekService::class,
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemoryDekRepository()),
    );
});

/**
 * Which side of this payment a taxpayer with these names is on.
 *
 * Resolved through the container so the name lookup is the real one —
 * `Kern\TaxRomania\Subjects\Tenancy\SubjectNames`, bound by this application. The package itself only
 * knows that something can hand it a list of names for a tenant id.
 *
 * @param  array<array-key, mixed>  $payment
 * @return array{direction: 'IN'|'OUT'|null, counterparty: string|null}
 */
function partiesOf(string $name, array $payment, ?string $identifier = null): array
{
    $subject = TaxSubject::factory()->create();
    $subject->display_name_ciphertext = $name;
    $subject->fiscal_identifier_kind = $identifier === null ? null : FiscalIdentifierKind::CUI;
    $subject->fiscal_identifier_ciphertext = $identifier;
    $subject->save();

    return app(ResolvePaymentParties::class)($subject->id, $payment);
}

it('reads a dividend to an associate as money in, from the company', function (): void {
    // The exact row that came back inverted: the account says "laur sandu", the decision says
    // "SANDU LAURENTIU-CONSTANTIN" — surname first, full given names, upper case.
    $parties = partiesOf('laur sandu', [
        'payer' => 'S.C. SENDWISE S.R.L.',
        'beneficiary' => 'SANDU LAURENTIU-CONSTANTIN',
        'amount_paid' => '279448',
    ]);

    expect($parties['direction'])->toBe('IN')
        ->and($parties['counterparty'])->toBe('S.C. SENDWISE S.R.L.');
});

it('reads the same decision as money out when the company is the one filing', function (): void {
    // One document, two subjects, opposite answers — which is why this is derived rather than
    // stored on the reading.
    $parties = partiesOf('SENDWISE SRL', [
        'payer' => 'S.C. SENDWISE S.R.L.',
        'beneficiary' => 'SANDU LAURENTIU-CONSTANTIN',
        'amount_paid' => '279448',
    ], 'RO37266479');

    expect($parties['direction'])->toBe('OUT')
        ->and($parties['counterparty'])->toBe('SANDU LAURENTIU-CONSTANTIN');
});

it('says nothing about a payment between two other people', function (): void {
    // The other two associates on the same decision. Their rows are facts about the document, not
    // transactions this person can record — and offering to import them would book somebody else's
    // money.
    $parties = partiesOf('laur sandu', [
        'payer' => 'S.C. SENDWISE S.R.L.',
        'beneficiary' => 'MOLDOVEANU MARIAN-CIPRIAN',
    ]);

    expect($parties['direction'])->toBeNull()
        ->and($parties['counterparty'])->toBeNull();
});

it('matches across diacritics and case', function (): void {
    $parties = partiesOf('Ștefan Măldăianu', [
        'payer' => 'SENDWISE SRL',
        'beneficiary' => 'MALDAIANU STEFAN',
    ]);

    expect($parties['direction'])->toBe('IN');
});

it('does not match a longer name against a shorter one', function (): void {
    // "Ion" may stand for "Ionescu" on the document; "Ionescu" must not stand for "Ion". The
    // account name is the shorter side of the test on purpose — otherwise every Ionescu in the
    // country matches an Ion.
    $parties = partiesOf('Ionescu Mihai', [
        'payer' => 'SENDWISE SRL',
        'beneficiary' => 'ION MIHAI',
    ]);

    expect($parties['direction'])->toBeNull();
});

it('matches a company on its fiscal code as well as its name', function (): void {
    $parties = partiesOf('Altceva SRL', [
        'payer' => 'RO37266479',
        'beneficiary' => 'SANDU LAURENTIU-CONSTANTIN',
    ], 'RO37266479');

    expect($parties['direction'])->toBe('OUT');
});

it('says nothing when the payment names nobody', function (): void {
    expect(partiesOf('laur sandu', ['amount_paid' => '100']))
        ->toBe(['direction' => null, 'counterparty' => null]);
});
