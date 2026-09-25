<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Parsing;

/**
 * GLEIF's BIC-to-LEI relationship file (`LEI-BIC-YYYYMMDD.zip`), published monthly with SWIFT.
 *
 * A ZIP holding one CSV with two columns, `LEI,BIC`. One LEI may have several BICs — a bank's head
 * office and its branches, and for a group, its banks in other countries — so the result keeps all
 * of them and the importer picks the one for the country in question.
 */
final readonly class BicLeiMappingParser
{
    private const string FILE = 'GLEIF BIC-to-LEI file';

    /** The CSV was 1.4 MB for 39 348 pairs; the limit is growth room, not permission to unpack anything. */
    private const int MAX_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;

    public function __construct(private SingleFileZipReader $zip)
    {
    }

    /**
     * @return array<string, list<string>> BICs keyed by LEI
     */
    public function parse(string $archive): array
    {
        $csv = $this->zip->firstFile($archive, self::FILE, self::MAX_UNCOMPRESSED_BYTES);
        $bics = [];

        foreach (DelimitedRows::fromCsv($csv, ['LEI', 'BIC'], self::FILE) as $row) {
            $lei = strtoupper($row['LEI']);
            $bic = strtoupper($row['BIC']);

            if ($lei !== '' && $bic !== '') {
                $bics[$lei][] = $bic;
            }
        }

        return $bics;
    }
}
