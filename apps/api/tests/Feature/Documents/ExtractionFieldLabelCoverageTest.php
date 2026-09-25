<?php

declare(strict_types=1);

use Kern\Documents\Enums\DocumentType;
use Kern\Documents\Parsing\ExtractionSchema;

/**
 * Every extraction field a document type can yield must have a label in every locale.
 *
 * The same guarantee `WalletLabelCoverageTest` provides for wallet enums, and it is needed here for
 * a sharper reason: `dictionary.ts` types `extractionField` as `Record<string, string>`, so `tsc`
 * cannot object to a missing key the way it does for a `Record<PaletteName, …>`. Nothing else in
 * the toolchain looks at these keys either — they are read with a `?? field` fallback, so a field
 * added to `ExtractionSchema` and forgotten here renders as `issuer_tax_id` in front of a person
 * and no check fails.
 *
 * The field list comes from the schemas themselves rather than from the `FIELD_*` constants: a
 * constant no document type uses cannot appear in the interface, and demanding a label for it would
 * be asking for translation of something nobody can see.
 *
 * `document_kind` is not among them — it is what the reading turned out to be, recorded on the
 * document rather than kept as a field of the reading, so it never reaches this map.
 */

/**
 * Every field name any document type declares, required or optional.
 *
 * @return list<string>
 */
function extractionFieldNames(): array
{
    $names = [];

    foreach (DocumentType::cases() as $type) {
        $names = [...$names, ...ExtractionSchema::for($type)->allFieldNames()];
    }

    return array_values(array_unique($names));
}

/**
 * The keys of the `extractionField` map in one locale.
 *
 * @return list<string>
 */
function extractionFieldLabelKeys(string $locale): array
{
    $source = file_get_contents(resource_path("js/i18n/{$locale}.ts"));

    if ($source === false) {
        throw new \RuntimeException("Could not read resources/js/i18n/{$locale}.ts.");
    }

    $mapStart = strpos($source, 'extractionField: {');

    if ($mapStart === false) {
        throw new \RuntimeException("Dictionary [{$locale}] has no `extractionField` map.");
    }

    $mapEnd = strpos($source, '}', $mapStart);
    $body = substr($source, $mapStart, ($mapEnd === false ? strlen($source) : $mapEnd) - $mapStart);

    // Wire names, so lower snake case — not the upper-case enum values the other coverage tests read.
    preg_match_all('/([a-z][a-z0-9_]*):\s*\'/', $body, $matches);

    return $matches[1];
}

it('has a label for every extraction field, in every locale', function (string $locale): void {
    // A set difference rather than case-by-case, so a failure names every missing label at once.
    expect(array_values(array_diff(extractionFieldNames(), extractionFieldLabelKeys($locale))))
        ->toBe([], "locale [{$locale}] is missing extraction field labels");
})->with(['ro', 'en']);

it('has no label for an extraction field that does not exist', function (string $locale): void {
    // The other direction: a renamed field leaves an orphan label behind, which reads as supported
    // until someone looks for the field it names.
    expect(array_values(array_diff(extractionFieldLabelKeys($locale), extractionFieldNames())))
        ->toBe([], "locale [{$locale}] has stale extraction field labels");
})->with(['ro', 'en']);
