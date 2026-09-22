<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\FiscalParameters\FormValueReaderTest;

use App\Domain\TaxKnowledge\FiscalParameters\Forms\FormText;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\FormValueReader;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\MinimumWages;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\Reading;
use App\Domain\TaxKnowledge\Models\FormStructure;
use DateTimeImmutable;

/**
 * Which year a formula is about, when the minimum wage changed after 1 January. The 2022 D212 file
 * caps the 2021 CASS base at 27 600 lei — 12 wages of 2 300, the wage from 13 January 2021 (HG
 * 4/2021); on 1 January 2021 it was still 2 230 (HG 935/2019). Its formula, as the extraction
 * service reads it.
 */
function wage(string $from, ?string $until, int $amount): Reading
{
    return new Reading('RO.MIN_WAGE', new DateTimeImmutable($from), $until === null ? null : new DateTimeImmutable($until), ['amount_minor' => $amount * 100, 'currency' => 'RON'], [], []);
}

it('counts an amount in a wage of the year that started after 1 January, and warns of nothing', function (): void {
    $wages = new MinimumWages([wage('2020-01-01', '2021-01-12', 2230), wage('2021-01-13', '2021-12-31', 2300)]);
    $form = new FormText(new FormStructure(['form_code' => 'D212', 'form_year' => 2022, 'url' => 'https://static.anaf.ro/structura_declaratieUnica_2022_05012022.pdf']), '34. cass_baza Baza anuală de calcul a CASS N(15) cass_baza >= 0 1150<=cass_baza <=27600 cass_baza trebuie sa fie multiplu de 1150');

    $cap = collect(app(FormValueReader::class)->read($form, $wages)->readings)->firstWhere('parameterCode', 'RO.CASS.ALL_INCOME.CAP_WAGES');

    expect($cap?->value)->toBe(['value' => 12])
        ->and($cap?->validFrom->format('Y-m-d'))->toBe('2021-01-01')
        // 2 300 lei was 2021's wage as much as 2 230 was: nothing to warn about.
        ->and($cap?->checks)->toBe([]);
});

it('never makes a value of an amount that is no whole number of the year\'s wages', function (): void {
    $wages = new MinimumWages([wage('2020-01-01', '2021-01-12', 2230), wage('2021-01-13', '2021-12-31', 2300)]);
    $form = new FormText(new FormStructure(['form_code' => 'D212', 'form_year' => 2021, 'url' => 'https://static.anaf.ro/structura_declaratieUnica_2021_12052021.pdf']), '34. cass_baza Baza anual ă de calcul a CASS N(15) cass_baza >= 0 1115<=cass_baza <=27360 cass_baza trebuie sa fie multiplu de 1115');

    $values = app(FormValueReader::class)->read($form, $wages);

    expect(collect($values->readings)->firstWhere('parameterCode', 'RO.CASS.ALL_INCOME.CAP_WAGES'))->toBeNull()
        ->and($values->unmatched)->toContain(['parameter' => 'RO.CASS.ALL_INCOME.CAP_WAGES', 'quote' => '1115<=cass_baza <=27360', 'kind' => 'not_whole_wages', 'year' => 2020, 'amount' => 27360, 'wage' => 2230]);
});
