<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banks\BankNameBuilderTest;

use App\Domain\Banks\DataTransferObjects\EcbInstitution;
use App\Domain\Banks\Parsing\LegalForms;
use App\Domain\Banks\Services\BankNameBuilder;

/**
 * Names as the ECB printed them for Romania on 2026-09-14, against the legal forms ISO 20275 lists
 * for their countries — held the way the parser holds them, as normalised words.
 */
function legalForms(): LegalForms
{
    return new LegalForms([
        'RO' => [['SA'], ['SRL'], ['SOCIETATE', 'PE', 'ACŢIUNI']],
        'PL' => [['SA'], ['SPÓŁKA', 'AKCYJNA']],
        'NL' => [['NV'], ['NAAMLOZE', 'VENNOOTSCHAP']],
        'LT' => [['UAB']],
        'HU' => [['ZRT'], ['ZÁRTKÖRŰEN', 'MŰKÖDŐ', 'RÉSZVÉNYTÁRSASÁG']],
    ]);
}

function bank(string $name): EcbInstitution
{
    return new EcbInstitution('RORBPJR00000', '', 'RO', $name, '', '', '', 'Credit Institution', '', '', '');
}

function branch(string $name, string $parentName, string $parentCountry): EcbInstitution
{
    return new EcbInstitution('RORBPJS00000', '', 'RO', $name, '', '', '', 'Credit Institution', $parentName, '', $parentCountry);
}

it('drops the closing legal form and gives an all-capitals name ordinary capitals', function (string $registered, string $expected): void {
    expect((new BankNameBuilder())->build(bank($registered), legalForms()))->toBe($expected);
})->with([
    ['BANCA TRANSILVANIA S.A.', 'Banca Transilvania'],
    ['RAIFFEISEN BANK SA', 'Raiffeisen Bank'],
    // Written without the final dot — the same form.
    ['SALT BANK S.A', 'Salt Bank'],
    // A short first word in capitals is an acronym, and keeps them.
    ['CEC BANK S.A.', 'CEC Bank'],
    // Joining words are lower-cased.
    ['BANCA ROMANA DE CREDITE SI INVESTITII SA', 'Banca Romana de Credite si Investitii'],
    // Mixed case is how the owner writes it, and is left alone.
    ['BRD - Groupe Societe Generale S.A.', 'BRD - Groupe Societe Generale'],
    ['BANCA CENTRALA COOPERATISTA CREDITCOOP', 'Banca Centrala Cooperatista Creditcoop'],
]);

it('keeps a bank’s leading letters that happen to spell a legal form', function (): void {
    expect((new BankNameBuilder())->build(bank('SA INVESTMENT BANK S.A.'), legalForms()))->toBe('SA Investment Bank');
});

it('names a branch by what comes before its first legal form', function (string $name, string $parent, string $country, string $expected): void {
    expect((new BankNameBuilder())->build(branch($name, $parent, $country), legalForms()))->toBe($expected);
})->with([
    ['ING Bank N.V., Amsterdam - Bucharest Branch', 'ING Bank N.V.', 'NL', 'ING Bank'],
    // Not the parent's registered Polish name.
    ['PKO BANK POLSKI S.A. VARSOVIA, SUCURSALA BUCURESTI', 'Powszechna Kasa Oszczędności Bank Polski S.A.', 'PL', 'PKO Bank Polski'],
    // UAB is Lithuanian: only the parent's country can cut there.
    ['REVOLUT BANK UAB VILNIUS SUCURSALA BUCURESTI', 'Revolut Bank UAB', 'LT', 'Revolut Bank'],
]);

it('falls back to the parent’s name, without its written-out form, when the branch name has no form', function (): void {
    $bankOfChina = branch(
        'BANK OF CHINA (CENTRAL AND EASTERN EUROPE) LIMITED SUCURSALA BUCURESTI',
        'Bank of China (Közép-Kelet-Európa) Zártkörűen Működő Részvénytársaság',
        'HU',
    );

    expect((new BankNameBuilder())->build($bankOfChina, legalForms()))->toBe('Bank of China (Közép-Kelet-Európa)');
});
