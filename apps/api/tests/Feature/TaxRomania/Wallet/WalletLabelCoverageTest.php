<?php

declare(strict_types=1);

use Kern\TaxRomania\Wallet\Enums\AccountKind;
use Kern\TaxRomania\Wallet\Enums\DayCountConvention;
use Kern\TaxRomania\Wallet\Enums\InterestMethod;
use Kern\TaxRomania\Wallet\Enums\PaymentFrequency;
use Kern\TaxRomania\Wallet\Enums\RateType;

/**
 * Every wallet enum the API can return must have a label in every locale.
 *
 * The same guarantee `SettingLabelCoverageTest` provides for `SettingKey`, and for the same reason:
 * `tsc` proves `ro.ts` and `en.ts` agree with *each other*, but nothing proves either agrees with a
 * PHP enum. This is the only place the two halves meet.
 *
 * It is here because that is exactly how these labels shipped wrong the first time. The dictionary
 * carried `CURRENT`, `SAVINGS`, `DEPOSIT`, `BROKERAGE` while `AccountKind` declares `BANK_CURRENT`,
 * `BANK_SAVINGS`, `CASH`, `TERM_DEPOSIT`, `BROKER`, `OTHER` — so every account rendered its raw
 * enum value. Nothing failed: not `tsc`, not PHPStan, not the page. The lookups simply fell through
 * to their `?? value` fallback, which is precisely what makes this class of bug invisible.
 */
function walletDictionarySource(string $locale): string
{
    $source = file_get_contents(resource_path("js/i18n/{$locale}.ts"));

    if ($source === false) {
        throw new \RuntimeException("Could not read resources/js/i18n/{$locale}.ts.");
    }

    return $source;
}

/**
 * The keys of one `name: { … }` map inside the shared `wallet` block.
 *
 * Scoped to that block so a same-named map elsewhere in the dictionary cannot satisfy the
 * assertion by accident.
 *
 * @return list<string>
 */
function walletLabelKeys(string $locale, string $map): array
{
    $source = walletDictionarySource($locale);

    $blockStart = strpos($source, '        wallet: {');

    if ($blockStart === false) {
        throw new \RuntimeException("Dictionary [{$locale}] has no `wallet` block.");
    }

    // The block ends at the next key indented to the same level.
    $blockEnd = strpos($source, "\n        walletOverview: {", $blockStart);
    $block = substr($source, $blockStart, ($blockEnd === false ? strlen($source) : $blockEnd) - $blockStart);

    $mapStart = strpos($block, $map . ': {');

    if ($mapStart === false) {
        throw new \RuntimeException("`wallet` block in [{$locale}] has no [{$map}] map.");
    }

    $mapEnd = strpos($block, '}', $mapStart);
    $body = substr($block, $mapStart, ($mapEnd === false ? strlen($block) : $mapEnd) - $mapStart);

    preg_match_all('/([A-Z][A-Z0-9_]*):/', $body, $matches);

    return $matches[1];
}

/**
 * @return array<string, list<string>>
 */
function walletEnumValues(): array
{
    return [
        'kinds' => array_map(static fn (AccountKind $case): string => $case->value, AccountKind::cases()),
        'frequencies' => array_map(static fn (PaymentFrequency $case): string => $case->value, PaymentFrequency::cases()),
        'rateTypes' => array_map(static fn (RateType $case): string => $case->value, RateType::cases()),
        'interestMethods' => array_map(static fn (InterestMethod $case): string => $case->value, InterestMethod::cases()),
        // No `sources` entry: a record's source is never shown as a word — the screens only ask
        // whether it is SYSTEM — so its labels were removed with the other texts nothing showed.
        // No `dayCounts` entry: the convention is never rendered. It is applied server-side from
        // `config/wallet.php` and never offered, shown or returned to a screen, so there are no
        // labels for it to cover — the test below guards the configured value instead.
    ];
}

it('has a label for every wallet enum case, in every locale', function (string $locale): void {
    foreach (walletEnumValues() as $map => $expected) {
        // A set difference rather than case-by-case, so a failure names every missing label at once
        // instead of stopping at the first.
        expect(array_values(array_diff($expected, walletLabelKeys($locale, $map))))
            ->toBe([], "locale [{$locale}] is missing labels in [{$map}]");
    }
})->with(['ro', 'en']);

it('has no label for a wallet enum case that does not exist', function (string $locale): void {
    // The other direction: a renamed enum case leaves an orphan label behind, which reads as
    // supported until someone looks for the value it names.
    foreach (walletEnumValues() as $map => $expected) {
        expect(array_values(array_diff(walletLabelKeys($locale, $map), $expected)))
            ->toBe([], "locale [{$locale}] has stale labels in [{$map}]");
    }
})->with(['ro', 'en']);

/**
 * The values of a `export const NAME = [...] as const` array in `types/wallet.ts`.
 *
 * @return list<string>
 */
function walletOptionConstant(string $name): array
{
    $source = file_get_contents(resource_path('js/types/wallet.ts'));

    if ($source === false) {
        throw new \RuntimeException('Could not read resources/js/types/wallet.ts.');
    }

    if (preg_match('/export const ' . preg_quote($name, '/') . ' = \[(.*?)\]/s', $source, $match) !== 1) {
        throw new \RuntimeException("types/wallet.ts has no [{$name}] constant.");
    }

    preg_match_all("/'([A-Z][A-Z0-9_]*)'/", $match[1], $values);

    return $values[1];
}

it('offers exactly the enum cases in every form select', function (): void {
    // The selects read from `as const` arrays in TypeScript rather than from the API, so those
    // arrays are a hand-written copy of a PHP enum. The server still rejects an invalid value with
    // `Rule::enum`, so a stale copy cannot corrupt data — but it can silently *omit* an option, and
    // a case nobody can choose is indistinguishable from one that does not exist.
    //
    // Order matters too: the forms default to index 0 (and to `PAYMENT_FREQUENCIES[4]`, AT_MATURITY,
    // the commonest term deposit), so a reordered enum would quietly change what a new record gets.
    $pairs = [
        'ACCOUNT_KINDS' => walletEnumValues()['kinds'],
        'RATE_TYPES' => walletEnumValues()['rateTypes'],
        'INTEREST_METHODS' => walletEnumValues()['interestMethods'],
        'PAYMENT_FREQUENCIES' => walletEnumValues()['frequencies'],
    ];

    foreach ($pairs as $constant => $expected) {
        expect(walletOptionConstant($constant))
            ->toBe($expected, "[{$constant}] in types/wallet.ts has drifted from its PHP enum");
    }
});

it('has a configured day-count default that is a real convention', function (): void {
    // The form does not offer this as a choice — the convention comes from the bank's contract, not
    // from the person recording the deposit — so `config/wallet.php` decides it for every new
    // deposit. A value that is not an enum case would silently fall back, applying a denominator
    // nobody chose to a figure that reaches a tax return.
    $configured = config('wallet.deposit.default_day_count');

    expect($configured)->toBeString();
    expect(DayCountConvention::tryFrom(is_string($configured) ? $configured : ''))->not->toBeNull();
});

it('offers deposit terms that are whole positive numbers of months', function (): void {
    $terms = config('wallet.deposit.terms_months');

    // The maturity date is derived by adding these to the opening date, so a zero, a negative or a
    // fraction would produce a term that is not a term at all.
    expect($terms)->toBeArray()->not->toBeEmpty();

    foreach (is_array($terms) ? $terms : [] as $months) {
        expect($months)->toBeInt()->toBeGreaterThan(0);
    }
});
