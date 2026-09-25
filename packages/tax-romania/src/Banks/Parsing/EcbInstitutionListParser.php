<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Banks\Parsing;

use Kern\TaxRomania\Banks\DataTransferObjects\EcbInstitution;
use Kern\TaxRomania\Banks\Parsing\Exceptions\InvalidBankSourceFileException;
use Throwable;

/**
 * The ECB's daily list of monetary financial institutions (`mfi_csv_YYMMDD.csv.gz`).
 *
 * As published, and checked against the file downloaded on 2026-09-15: gzip-compressed, UTF-16
 * little-endian, tab-separated, one institution per line, with a header row naming the columns.
 * Every EU country is in the one file; filtering by country is the importer's job, not this one's.
 *
 * The ECB added the LEI columns in 2019: the archived files before that have the same columns in
 * the same meaning, without `LEI` and `HEAD_LEI`, so those two are read when present and empty when
 * not. The rest is required either way.
 */
final class EcbInstitutionListParser
{
    private const string FILE = 'ECB institution list';

    /** The uncompressed file was 2 MB for the whole EU; this leaves room for growth, not for a bomb. */
    private const int MAX_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;

    private const string GZIP_MAGIC = "\x1f\x8b";

    private const array COLUMNS = [
        'RIAD_CODE',
        'COUNTRY_OF_REGISTRATION',
        'NAME',
        'ADDRESS',
        'POSTAL',
        'CITY',
        'CATEGORY',
        'HEAD_COUNTRY_OF_REGISTRATION',
        'HEAD_NAME',
    ];

    /**
     * @return list<EcbInstitution>
     */
    public function parse(string $bytes): array
    {
        $rows = DelimitedRows::fromTsv($this->toUtf8($this->inflate($bytes)), self::COLUMNS, self::FILE);
        $institutions = [];

        foreach ($rows as $row) {
            // The code is the row's identity and the name is what a person picks; a row missing
            // either is not an institution anyone could choose.
            if ($row['RIAD_CODE'] === '' || $row['NAME'] === '') {
                continue;
            }

            $institutions[] = new EcbInstitution(
                ecbCode: $row['RIAD_CODE'],
                lei: $row['LEI'] ?? '',
                countryCode: strtoupper($row['COUNTRY_OF_REGISTRATION']),
                name: $row['NAME'],
                address: $row['ADDRESS'],
                postalCode: $row['POSTAL'],
                city: $row['CITY'],
                category: $row['CATEGORY'],
                parentName: $row['HEAD_NAME'],
                parentLei: $row['HEAD_LEI'] ?? '',
                parentCountryCode: strtoupper($row['HEAD_COUNTRY_OF_REGISTRATION']),
            );
        }

        return $institutions;
    }

    private function inflate(string $bytes): string
    {
        // Accepted uncompressed too, which is the same file served without the `.gz`.
        if (! str_starts_with($bytes, self::GZIP_MAGIC)) {
            return $bytes;
        }

        try {
            $inflated = gzdecode($bytes, self::MAX_UNCOMPRESSED_BYTES);
        } catch (Throwable) {
            $inflated = false;
        }

        if (! is_string($inflated)) {
            throw InvalidBankSourceFileException::unreadable(self::FILE, 'the gzip data is corrupt or over the size limit');
        }

        return $inflated;
    }

    private function toUtf8(string $raw): string
    {
        [$encoding, $offset] = match (true) {
            str_starts_with($raw, "\xFF\xFE") => ['UTF-16LE', 2],
            str_starts_with($raw, "\xFE\xFF") => ['UTF-16BE', 2],
            // No byte-order mark but a zero second byte: ASCII text in UTF-16LE.
            strlen($raw) > 1 && $raw[1] === "\0" => ['UTF-16LE', 0],
            default => ['UTF-8', 0],
        };

        if ($encoding === 'UTF-8') {
            return $raw;
        }

        return mb_convert_encoding(substr($raw, $offset), 'UTF-8', $encoding);
    }
}
