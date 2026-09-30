<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\Banks\BankKindTest;

use Kern\TaxRomania\Banks\Enums\BankKind;
use Kern\TaxRomania\Banks\Services\BankKindResolver;

/**
 * Reading an institution's kind out of the number its national register gave it.
 *
 * The ECB's own `CATEGORY` says `Credit Institution` for every row we keep, so the distinction lives
 * in the registration number and nowhere else — and those numbers are national, which is why the
 * table in `config/banks.php` is keyed by country.
 */
function resolver(): BankKindResolver
{
    return app(BankKindResolver::class);
}

it('reads the kind from real registration numbers', function (string $code, BankKind $kind): void {
    expect(resolver()->for('RO', $code))->toBe($kind);
})->with([
    // Every one of these is a row that exists, not an invented example.
    'Banca Comercială Română' => ['RORBPJR40008', BankKind::BANK],
    'Banca Transilvania' => ['RORBPJR12019', BankKind::BANK],
    'ING Bank, Bucharest branch' => ['RORBPJS40024', BankKind::FOREIGN_BRANCH],
    'TBI Bank, Sofia branch' => ['RORBPJS40068', BankKind::FOREIGN_BRANCH],
    'Banca Cooperatistă Albina Slatina' => ['ROCCCO01280409', BankKind::COOPERATIVE],
    'Creditcoop, the central house' => ['ROCCCC01400001', BankKind::COOPERATIVE],
]);

it('tells a Romanian bank from a branch by the character they differ at', function (): void {
    // `RORBPJR` and `RORBPJS` are the same until the seventh character. A rule that matched a shorter
    // prefix would answer the same for both, and the answer would be wrong for fourteen rows.
    expect(resolver()->for('RO', 'RORBPJR40008'))->toBe(BankKind::BANK)
        ->and(resolver()->for('RO', 'RORBPJS40008'))->toBe(BankKind::FOREIGN_BRANCH);
});

it('matches the official spelling against the file\'s spelling', function (): void {
    // The config holds `RB-PJR` as the regulation writes it; the file prints `RORBPJR40008`. Both are
    // reduced to letters and digits before they meet, which is what lets the table be checked against
    // its source by eye.
    expect(resolver()->for('RO', 'RO-RB-PJR-40-008'))->toBe(BankKind::BANK)
        ->and(resolver()->for('ro', 'rorbpjr40008'))->toBe(BankKind::BANK);
});

it('will not match a pattern found in the middle of a number', function (): void {
    // Anchored, deliberately. These numbers carry a county code and an order number, and a pattern
    // allowed to match anywhere would eventually meet one by accident.
    expect(resolver()->for('RO', '99999RBPJR40008'))->toBe(BankKind::UNKNOWN);
});

it('answers UNKNOWN for a country with no table, rather than guessing', function (): void {
    // So widening `BANKS_COUNTRIES` before writing that country's codes is visible instead of
    // silently wrong: a German number means nothing under Romanian rules.
    expect(resolver()->for('DE', 'DE059012345'))->toBe(BankKind::UNKNOWN);
});

it('answers UNKNOWN for the codes no regulation we have read defines', function (): void {
    /*
     * Four real rows, left out of the table on purpose.
     *
     * `ROBDPJR40001` is Banca de Investiții și Dezvoltare, registered in November 2025 under letters
     * that appear in no regulation found; `RO41819399` is Bank of China's branch carrying a purely
     * numeric code; `ROROPJS40074` looks mistyped at source, since the other fourteen branches are
     * `RORBPJS`; `ROISP` is a five-character stub from an archived list. Calling any of them `BANK`
     * would assert what the register does not say.
     */
    expect(resolver()->for('RO', 'ROBDPJR40001'))->toBe(BankKind::UNKNOWN)
        ->and(resolver()->for('RO', 'RO41819399'))->toBe(BankKind::UNKNOWN)
        ->and(resolver()->for('RO', 'ROROPJS40074'))->toBe(BankKind::UNKNOWN)
        ->and(resolver()->for('RO', 'ROISP'))->toBe(BankKind::UNKNOWN);
});

it('answers UNKNOWN rather than throwing on an empty code', function (): void {
    expect(resolver()->for('RO', ''))->toBe(BankKind::UNKNOWN)
        ->and(resolver()->for('', ''))->toBe(BankKind::UNKNOWN);
});

it('names every kind in the table as a case of the enum', function (): void {
    // A typo in the config would otherwise resolve to UNKNOWN and look like an unrecognised bank
    // rather than a broken table.
    /** @var array<string, array<string, list<string>>> $table */
    $table = config()->array('banks.kinds');
    $unknown = [];

    foreach ($table as $country => $kinds) {
        foreach (array_keys($kinds) as $kind) {
            if (BankKind::tryFrom((string) $kind) === null) {
                $unknown[] = "{$country}.{$kind}";
            }
        }
    }

    expect($unknown)->toBe([]);
});
