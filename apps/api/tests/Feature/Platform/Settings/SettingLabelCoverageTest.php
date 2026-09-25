<?php

declare(strict_types=1);

use Kern\Platform\Settings\Enums\SettingGroup;
use Kern\Platform\Settings\Enums\SettingKey;
use RuntimeException;

/**
 * Every setting the API can return must have a label in every locale, and every label must name a
 * setting that exists.
 *
 * This is asserted from PHP against the TypeScript dictionaries because that is the only place the
 * two halves meet: `tsc` proves `ro.ts` and `en.ts` agree with each other, but nothing proves they
 * agree with `SettingKey`. A renamed key otherwise ships silently and the screen renders the raw
 * key — `sources.fetch_max_response_bytes` vs `sources.fetch_max_bytes` did exactly that, and was
 * only caught by looking at the rendered page.
 */
function dictionarySource(string $locale): string
{
    $source = file_get_contents(resource_path("js/i18n/{$locale}.ts"));

    if ($source === false) {
        throw new RuntimeException("Could not read resources/js/i18n/{$locale}.ts.");
    }

    return $source;
}

/** The body of one `name: { … }` object literal, up to its closing brace. */
function dictionaryBlock(string $source, string $name): string
{
    $marker = $name . ': {';
    $start = strpos($source, $marker);

    if ($start === false) {
        throw new RuntimeException("Dictionary has no [{$marker}] block.");
    }

    $end = strpos($source, '},', $start);

    if ($end === false) {
        throw new RuntimeException("Dictionary block [{$marker}] is not closed.");
    }

    return substr($source, $start, $end - $start);
}

/**
 * @return list<string>
 */
function dictionaryLabelKeys(string $locale): array
{
    preg_match_all(
        "/'([a-z0-9_.]+)':/",
        dictionaryBlock(dictionarySource($locale), 'settingLabels'),
        $matches,
    );

    return $matches[1];
}

it('has a label for every setting key, in every locale', function (string $locale): void {
    $known = array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases());

    // Asserted as a set difference rather than key-by-key: a failure then prints exactly which
    // keys are missing, instead of stopping at the first one.
    expect(array_values(array_diff($known, dictionaryLabelKeys($locale))))->toBe([]);
})->with(['ro', 'en']);

it('has no label for a setting that no longer exists', function (string $locale): void {
    $known = array_map(static fn (SettingKey $key): string => $key->value, SettingKey::cases());

    expect(array_values(array_diff(dictionaryLabelKeys($locale), $known)))->toBe([]);
})->with(['ro', 'en']);

it('has a name and a hint for every group, in every locale', function (string $locale): void {
    $source = dictionarySource($locale);

    // Both maps are keyed by the bare group name, so asserting against the whole file would pass
    // on one map alone; each block is searched separately.
    foreach (['groups', 'groupHints'] as $block) {
        $body = dictionaryBlock($source, $block);

        foreach (SettingGroup::cases() as $group) {
            expect($body)->toContain($group->value . ':');
        }
    }
})->with(['ro', 'en']);
