<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\NoteReaderTest;

use Kern\Legislation\Services\NoteReader;

/**
 * Every note here is verbatim from the portal: the 2026-08-08 consolidation of the Codul fiscal.
 *
 * @return array{text: string, acts: list<array<string, mixed>>, applies: list<array<string, mixed>>} */
function read(string $note): array
{
    return app(NoteReader::class)->read($note);
}

it('reads the act a note quotes and the fiscal year it applies from', function (): void {
    $read = read('Potrivit alin. (2) al art. 10 din ORDONANȚA DE URGENȚĂ nr. 8 din 24 februarie 2026, publicată în MONITORUL OFICIAL nr. 147 din 25 februarie 2026, prevederile art. 6 pct. 6 se aplică începând cu anul fiscal 2026.');

    expect($read['acts'])->toBe([[
        'type' => 'ORDONANȚA DE URGENȚĂ',
        'number' => '8',
        'date' => '2026-02-24',
        'journal_number' => '147',
        'journal_date' => '2026-02-25',
    ]])->and($read['applies'])->toBe([[
        'kind' => 'fiscal_year',
        'from' => '2026-01-01',
        'until' => null,
        'year' => 2026,
        'act' => 0,
        'quote' => 'începând cu anul fiscal 2026',
    ]]);
});

it('reads the income a change applies to, by year and by month', function (): void {
    $year = read('Conform literei b) a alineatului (2) al articolului XIII din LEGEA nr. 239 din 15 decembrie 2025, publicată în MONITORUL OFICIAL nr. 1160 din 15 decembrie 2025, prevederile art. XII pct. 19 se aplică începând cu veniturile aferente anului 2026.');
    // The note's opening, as far as the date.
    $month = read('Conform articolului V din ORDONANȚA DE URGENȚĂ nr. 87 din 28 iunie 2024, publicată în MONITORUL OFICIAL nr. 608 din 28 iunie 2024, începând cu veniturile din salarii și asimilate salariilor aferente lunii iulie 2024, în venitul brut astfel cum este definit la art. 76 alin. (1)-(3) din Legea nr. 227/2015 privind Codul fiscal');

    expect($year['applies'][0])->toMatchArray(['kind' => 'income_year', 'from' => '2026-01-01', 'year' => 2026])
        ->and($month['applies'][0])->toMatchArray([
            'kind' => 'income_month',
            'from' => '2024-07-01',
            'quote' => 'veniturile din salarii și asimilate salariilor aferente lunii iulie 2024',
        ]);
});

it('gives each date to the last act named before it', function (): void {
    $read = read('Conform alin. (3) al art. II din ORDONANȚA DE URGENȚĂ nr. 89 din 23 decembrie 2025, publicată în MONITORUL OFICIAL nr. 1203 din 24 decembrie 2025, astfel cum a fost modificat de punctul 1 al articolului XI din ORDONANȚA DE URGENȚĂ nr. 9 din 27 februarie 2026, publicată în MONITORUL OFICIAL nr. 157 din 27 februarie 2026, prin excepție de la alin. (1), prevederile art. I pct. 9-51 intră în vigoare la data de 31 martie 2026.');

    expect(array_column($read['acts'], 'number'))->toBe(['89', '9'])
        ->and($read['applies'])->toHaveCount(1)
        ->and($read['applies'][0])->toMatchArray(['kind' => 'in_force', 'from' => '2026-03-31', 'act' => 1]);
});

it('reads a period, and an end with no start, each once', function (): void {
    $read = read('Conform articolului II din ORDONANȚA nr. 6 din 28 ianuarie 2020, publicată în MONITORUL OFICIAL nr. 72 din 31 ianuarie 2020, aplicarea prevederilor art. 324 alin. (4)-(6) din Legea nr. 227/2015 privind Codul fiscal se suspendă până la data de 31 decembrie 2022.Conform articolului XXXV, Capitolul I din ORDONANȚA DE URGENȚĂ nr. 168 din 8 decembrie 2022, publicată în MONITORUL OFICIAL nr. 1186 din 9 decembrie 2022, aplicarea prevederilor art. 324 alin. (4)-(6) din Legea nr. 227/2015, cu modificările și completările ulterioare, se suspendă începând cu data de 1 ianuarie 2023 și până la data de 31 decembrie 2023 inclusiv.');

    expect(array_map(static fn (array $applies): array => [$applies['kind'], $applies['from'], $applies['until'], $applies['act']], $read['applies']))->toBe([
        ['until_date', null, '2022-12-31', 0],
        ['from_date', '2023-01-01', '2023-12-31', 1],
    ]);
});

it('dates what applies on publication by the journal the act was published in', function (): void {
    $read = read('Potrivit XVI din ORDONANȚA DE URGENȚĂ nr. 26 din 21 martie 2024, publicată în MONITORUL OFICIAL nr. 254 din 25 martie 2024, prevederile art. X se aplică începând cu veniturile aferente lunii următoare publicării în Monitorul Oficial al României, Partea I, a prezentei ordonanțe de urgență.');

    expect($read['applies'][0])->toMatchArray(['kind' => 'income_month_after_publication', 'from' => '2024-04-01', 'act' => 0]);
});

it('gives no date where the note gives none', function (): void {
    $modified = read('Conform alineatului (2) al articolului II din ORDONANȚA DE URGENȚĂ nr. 89 din 23 decembrie 2025, publicată în MONITORUL OFICIAL nr. 1203 din 24 decembrie 2025, pentru contribuabilii care intră sub incidența prevederilor art. 16 alin. (5) din Legea nr. 227/2015, cu modificările și completările ulterioare, prevederile art. I pct. 1-3 se aplică începând cu anul fiscal modificat care începe în anul 2026.');
    $decision = read('Decizie de respingere: HP nr. 74/2021, publicată în Monitorul Oficial nr. 1174 din 13 decembrie 2021.');

    expect($modified['applies'])->toHaveCount(1)
        ->and($modified['applies'][0])->toMatchArray(['kind' => 'modified_fiscal_year', 'from' => null, 'year' => 2026])
        ->and($decision['acts'])->toBe([])
        ->and($decision['applies'])->toBe([]);
});
