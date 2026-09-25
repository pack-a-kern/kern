<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Documents\ResolvePaymentPartiesTest;

use App\Domain\Documents\Payments\ResolvePaymentParties;
use App\Domain\Subjects\Enums\FiscalIdentifierKind;
use App\Domain\Subjects\Models\TaxSubject;
use Kern\Platform\KeyManagement\SubjectDekService;
use Tests\Fakes\InMemoryKeyManagementService;
use Tests\Fakes\InMemorySubjectDekRepository;

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
        new SubjectDekService(new InMemoryKeyManagementService(), new InMemorySubjectDekRepository()),
    );
});

function subjectCalled(string $name, ?string $identifier = null): TaxSubject
{
    $subject = TaxSubject::factory()->create();
    $subject->display_name_ciphertext = $name;
    $subject->fiscal_identifier_kind = $identifier === null ? null : FiscalIdentifierKind::CUI;
    $subject->fiscal_identifier_ciphertext = $identifier;
    $subject->save();

    return $subject;
}

it('reads a dividend to an associate as money in, from the company', function (): void {
    // The exact row that came back inverted: the account says "laur sandu", the decision says
    // "SANDU LAURENTIU-CONSTANTIN" — surname first, full given names, upper case.
    $parties = (new ResolvePaymentParties())(subjectCalled('laur sandu'), [
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
    $parties = (new ResolvePaymentParties())(subjectCalled('SENDWISE SRL', 'RO37266479'), [
        'payer' => 'S.C. SENDWISE S.R.L.',
        'beneficiary' => 'SANDU LAURENTIU-CONSTANTIN',
        'amount_paid' => '279448',
    ]);

    expect($parties['direction'])->toBe('OUT')
        ->and($parties['counterparty'])->toBe('SANDU LAURENTIU-CONSTANTIN');
});

it('says nothing about a payment between two other people', function (): void {
    // The other two associates on the same decision. Their rows are facts about the document, not
    // transactions this person can record — and offering to import them would book somebody else's
    // money.
    $parties = (new ResolvePaymentParties())(subjectCalled('laur sandu'), [
        'payer' => 'S.C. SENDWISE S.R.L.',
        'beneficiary' => 'MOLDOVEANU MARIAN-CIPRIAN',
    ]);

    expect($parties['direction'])->toBeNull()
        ->and($parties['counterparty'])->toBeNull();
});

it('matches across diacritics and case', function (): void {
    $parties = (new ResolvePaymentParties())(subjectCalled('Ștefan Măldăianu'), [
        'payer' => 'SENDWISE SRL',
        'beneficiary' => 'MALDAIANU STEFAN',
    ]);

    expect($parties['direction'])->toBe('IN');
});

it('does not match a longer name against a shorter one', function (): void {
    // "Ion" may stand for "Ionescu" on the document; "Ionescu" must not stand for "Ion". The
    // account name is the shorter side of the test on purpose — otherwise every Ionescu in the
    // country matches an Ion.
    $parties = (new ResolvePaymentParties())(subjectCalled('Ionescu Mihai'), [
        'payer' => 'SENDWISE SRL',
        'beneficiary' => 'ION MIHAI',
    ]);

    expect($parties['direction'])->toBeNull();
});

it('matches a company on its fiscal code as well as its name', function (): void {
    $parties = (new ResolvePaymentParties())(subjectCalled('Altceva SRL', 'RO37266479'), [
        'payer' => 'RO37266479',
        'beneficiary' => 'SANDU LAURENTIU-CONSTANTIN',
    ]);

    expect($parties['direction'])->toBe('OUT');
});

it('says nothing when the payment names nobody', function (): void {
    expect((new ResolvePaymentParties())(subjectCalled('laur sandu'), ['amount_paid' => '100']))
        ->toBe(['direction' => null, 'counterparty' => null]);
});
