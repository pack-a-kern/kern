<?php

declare(strict_types=1);

namespace App\Domain\Banks\Parsing;

/**
 * ISO 20275, the Entity Legal Forms code list GLEIF maintains (`YYYY-MM-DD-elf-code-list-vX.Y.csv`).
 *
 * Kept per country: every abbreviation and every written-out name, local and transliterated. Several
 * abbreviations share one cell, separated by `;` (`NV;nv;N.V.;n.v.`). An abbreviation cell that is a
 * description rather than an abbreviation — `DAC – (limited by guarantee)` — has more than one word
 * and is skipped: the names in the list already say what the form is called.
 */
final class LegalFormListParser
{
    private const string FILE = 'ISO 20275 legal forms list';

    private const string COUNTRY = 'Country Code (ISO 3166-1)';

    private const array NAMES = [
        'Entity Legal Form name Local name',
        'Entity Legal Form name Transliterated name (per ISO 01-140-10)',
    ];

    private const array ABBREVIATIONS = ['Abbreviations Local language', 'Abbreviations transliterated'];

    public function parse(string $csv): LegalForms
    {
        $byCountry = [];

        foreach (DelimitedRows::fromCsv($csv, [self::COUNTRY, ...self::NAMES, ...self::ABBREVIATIONS], self::FILE) as $row) {
            $country = strtoupper($row[self::COUNTRY]);

            if ($country === '') {
                continue;
            }

            foreach (self::NAMES as $column) {
                $byCountry[$country][] = self::words($row[$column]);
            }

            foreach (self::ABBREVIATIONS as $column) {
                foreach (explode(';', $row[$column]) as $abbreviation) {
                    $words = self::words($abbreviation);

                    if (count($words) === 1) {
                        $byCountry[$country][] = $words;
                    }
                }
            }
        }

        return new LegalForms(array_map(
            static fn (array $forms): array => array_values(array_unique(
                array_filter($forms, static fn (array $words): bool => $words !== []),
                SORT_REGULAR,
            )),
            $byCountry,
        ));
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return array_values(array_filter(
            array_map(LegalForms::normalise(...), preg_split('/\s+/u', $text) ?: []),
            static fn (string $word): bool => $word !== '',
        ));
    }
}
