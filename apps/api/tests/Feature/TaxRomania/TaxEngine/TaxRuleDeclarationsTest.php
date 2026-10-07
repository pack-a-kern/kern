<?php

declare(strict_types=1);

namespace Tests\Feature\TaxRomania\TaxEngine\TaxRuleDeclarationsTest;

use Illuminate\Support\Facades\Artisan;
use Kern\TaxRomania\TaxEngine\Attributes\TaxRule as TaxRuleAttribute;
use Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract;
use ReflectionClass;

/**
 * What every rule declares about itself, checked against the vocabularies it names.
 *
 * ## Why this is the test that matters most here
 *
 * A mistyped parameter code fails loudly — the resolver throws when a rule asks for something it was not given.
 * A mistyped *category* does not. `'RENTAL'` instead of `'RENT'` matches no facts, sums to zero, and reports a
 * tax of nothing: a confident wrong answer, on a tax return, that looks exactly like a correct one. Nothing in
 * the type system can catch it, because both are strings.
 *
 * So the categories a rule claims are checked against `config/reference/tax_categories.php`, which is where the
 * vocabulary actually lives — the same file the transactions screen, the importer and the classifier read.
 *
 * ## And it walks the directory
 *
 * Not a list of rules maintained here. A rule added without a declaration, or with one that does not match the
 * vocabulary, fails without anybody remembering to extend this file — which is the only version of this that
 * stays true.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/** @return list<class-string<TaxRuleContract>> */
function ruleClasses(): array
{
    // Through the suite's own `packageRoot()`, as `RulePurityArchitectureTest` does: the package lives at a
    // different path inside the container than on the host, and a relative walk from this file got it wrong —
    // which the first assertion in this file caught, because the other three had been passing over an empty list.
    $directory = packageRoot(TaxRuleContract::class) . '/src/TaxEngine/Rules';
    $classes = [];

    foreach (glob($directory . '/*.php') ?: [] as $file) {
        /** @var class-string $class */
        $class = 'Kern\\TaxRomania\\TaxEngine\\Rules\\' . basename($file, '.php');

        if (is_subclass_of($class, TaxRuleContract::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/** @param class-string $class */
function attributeOf(string $class): TaxRuleAttribute
{
    $attribute = (new ReflectionClass($class))->getAttributes(TaxRuleAttribute::class)[0] ?? null;

    expect($attribute)->not->toBeNull("{$class} carries no #[TaxRule] attribute");

    /** @var TaxRuleAttribute $instance */
    $instance = $attribute?->newInstance();

    return $instance;
}

it('finds the rules at all, so a passing run means something', function (): void {
    expect(ruleClasses())->not->toBe([]);
});

it('declares only categories the vocabulary actually has', function (): void {
    /** @var array<string, string> $income */
    $income = config('reference.tax_categories.income', []);
    /** @var array<string, string> $notIncome */
    $notIncome = config('reference.tax_categories.not_income', []);
    $known = [...array_keys($income), ...array_keys($notIncome)];

    $unknown = [];

    foreach (ruleClasses() as $class) {
        foreach (attributeOf($class)->categories as $category) {
            if (! in_array($category, $known, true)) {
                $unknown[] = class_basename($class) . ' -> ' . $category;
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('taxes only income, never a category that is somebody moving their own money', function (): void {
    // A rule listing `TRANSFER` would be taxing a person's own money. The producer already excludes non-income
    // when building facts, so such a rule would simply never fire — which hides the mistake rather than
    // surfacing it.
    /** @var array<string, string> $income */
    $income = config('reference.tax_categories.income', []);
    $incomeCodes = array_keys($income);

    $wrong = [];

    foreach (ruleClasses() as $class) {
        foreach (attributeOf($class)->categories as $category) {
            if (! in_array($category, $incomeCodes, true)) {
                $wrong[] = class_basename($class) . ' -> ' . $category;
            }
        }
    }

    expect($wrong)->toBe([]);
});

it('names a code and a revision, and the revision is not a year pretending to be one', function (): void {
    /*
     * `2026.1` used to sit here and read like a statement about the law. It was a label, and the year in it was
     * being chopped off to decide when the rule applied — so a readability choice became an effective date and
     * nine years of approved values went unreachable.
     */
    $suspicious = [];

    foreach (ruleClasses() as $class) {
        $meta = attributeOf($class);

        expect($meta->code)->not->toBe('');
        expect($meta->version)->not->toBe('');

        if (preg_match('/^(19|20)\d{2}/', $meta->version) === 1) {
            $suspicious[] = class_basename($class) . ' -> ' . $meta->version;
        }
    }

    expect($suspicious)->toBe([]);
});

it('declares every parameter it reads, since a rule is given only what it declared', function (): void {
    // `ParameterResolver` works from the declaration, so a parameter read but not declared throws at run time —
    // on a real assessment, for a real taxpayer. Asserting the declaration is non-empty does not prove the two
    // agree, but a rule declaring nothing and reading something is the case that has actually happened.
    foreach (ruleClasses() as $class) {
        expect(attributeOf($class)->parameters)->not->toBe([], class_basename($class) . ' declares no parameters');
    }
});
