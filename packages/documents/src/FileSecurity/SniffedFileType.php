<?php

declare(strict_types=1);

namespace Kern\Documents\FileSecurity;

/**
 * The closed set of file types this pipeline accepts, and everything that is true about each one.
 *
 * Identified from the file's own bytes (`BLUEPRINT.md` §14.2 "ignore supplied MIME") — never from a
 * client-supplied `Content-Type` header or filename extension.
 *
 * ## Why this enum owns so much
 *
 * The accepted list used to exist in four places that did not know about each other: this enum, the
 * signature constants in `MagicByteSniffer`, a `str_starts_with($mime, 'image/')` in the AI request
 * builder — which silently admitted `image/webp`, a type nothing else here accepts — and a
 * hand-typed `accept="image/*,application/pdf"` in the upload dialog. Four copies of one fact is
 * four chances for one of them to be forgotten, and the one that gets forgotten is always the
 * picker, which then offers a file the server refuses.
 *
 * So a type now answers every question about itself: how to recognise it, what it may be called,
 * how large it may be, and how the pipeline reads it. Adding a type is one case plus the branch in
 * `Kern\Documents\Jobs\ProcessScannedDocument` that knows what to do with it — which the
 * `match` there makes impossible to forget, because PHP will not compile an unhandled case.
 *
 * The list is deliberately *not* configurable. A type is not a preference: each one needs code that
 * can read it, and an operator adding `image/tiff` to an env var would get a file the pipeline
 * accepts, stores, scans and then has no idea what to do with — a setting that appears to work and
 * silently produces nothing.
 */
enum SniffedFileType: string
{
    case PDF = 'application/pdf';
    case JPEG = 'image/jpeg';
    case PNG = 'image/png';
    case CSV = 'text/csv';
    case XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case XLS = 'application/vnd.ms-excel';

    /** @return list<string> lower-case extensions a declared filename may legitimately carry. */
    public function allowedExtensions(): array
    {
        return match ($this) {
            self::PDF => ['pdf'],
            self::JPEG => ['jpg', 'jpeg'],
            self::PNG => ['png'],
            self::CSV => ['csv'],
            self::XLSX => ['xlsx'],
            self::XLS => ['xls'],
        };
    }

    /**
     * Whether this file's own leading bytes say it is of this type.
     *
     * PDF is matched with a bounded scan window rather than an offset-0 check: ISO 32000-2 §7.5.2
     * permits leading bytes before the header, which some generators prepend. JPEG and PNG put
     * their headers at offset 0 by their own specifications, so no window applies.
     *
     * CSV has no signature at all — it is text, and no byte sequence distinguishes it from any
     * other text. It is therefore matched *structurally* and last (see {@link sniff()}), and
     * strictly: a text file that is not delimited is not a CSV, and is refused rather than accepted
     * as one the pipeline would then fail to read.
     *
     * @param  string  $leadingBytes  the first {@link SNIFF_WINDOW_BYTES} of the file, or all of it
     */
    public function matches(string $leadingBytes): bool
    {
        return match ($this) {
            self::PDF => str_contains(substr($leadingBytes, 0, self::PDF_SCAN_WINDOW_BYTES), '%PDF-'),
            self::JPEG => str_starts_with($leadingBytes, "\xFF\xD8\xFF"),
            self::PNG => str_starts_with($leadingBytes, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"),
            self::CSV => self::looksLikeDelimitedText($leadingBytes),
            // An OOXML package: the zip signature, and the part list every one of them opens with.
            //
            // This does not tell a workbook from a Word file — both are zips with the same first
            // entry, and the entry that would distinguish them (`xl/workbook.xml`) sits past this
            // window in a file of any size. The statement parser opens the zip properly and refuses
            // a document with `UNSUPPORTED_FORMAT`, so the narrower check happens where the whole
            // file is available rather than being guessed at from a kilobyte.
            self::XLSX => str_starts_with($leadingBytes, "PK\x03\x04")
                && str_contains($leadingBytes, '[Content_Types].xml'),
            // The OLE2 compound-document header, which is also what a `.doc` and a `.msg` carry —
            // same reasoning as above, and the same parser answers it.
            self::XLS => str_starts_with($leadingBytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        };
    }

    /**
     * The file's real type, or null if it is none of them.
     *
     * Order is load-bearing: the signature-matched types are tried first and CSV last, because
     * CSV's test is structural and a binary file that happened to begin with printable, delimited
     * bytes would otherwise be admitted as a spreadsheet.
     */
    public static function sniff(string $leadingBytes): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->matches($leadingBytes)) {
                return $type;
            }
        }

        return null;
    }

    public function isImage(): bool
    {
        return $this === self::JPEG || $this === self::PNG;
    }

    /**
     * What a browser's file picker should offer.
     *
     * MIME types *and* extensions, because browsers are inconsistent about which they honour —
     * some match only on extension for types they do not know, and `text/csv` is one they often do
     * not. Served to the frontend through Inertia's shared props rather than retyped there.
     */
    public static function acceptAttribute(): string
    {
        $entries = [];

        foreach (self::cases() as $type) {
            $entries[] = $type->value;

            foreach ($type->allowedExtensions() as $extension) {
                $entries[] = '.' . $extension;
            }
        }

        return implode(',', $entries);
    }

    /** How many leading bytes {@link matches()} needs to decide. */
    public const int SNIFF_WINDOW_BYTES = 1024;

    private const int PDF_SCAN_WINDOW_BYTES = 1024;

    /**
     * Printable text whose first two lines are delimited the same way.
     *
     * The only honest test available for a format with no signature, and it has to be stricter than
     * "contains a comma" — the sentence *"just some plain text, not a document at all"* contains a
     * comma, and calling that a spreadsheet would let any prose file into a pipeline that then has
     * nothing to do with it.
     *
     * So the test is *consistency*: a spreadsheet has the same number of columns on every row, and
     * prose does not. Two lines with an equal, non-zero count of the same delimiter is what a table
     * looks like and what a paragraph almost never does.
     *
     * Before that, two cheaper refusals:
     *
     *  - **No NUL bytes.** Every binary container has one in its first kilobyte; text never does.
     *    This is what stops a `.docx` or a zip from arriving as a spreadsheet.
     *  - **Valid UTF-8.** The text will be stored, shown and sent to a model. Bytes that are not
     *    text in an encoding this application handles are not a spreadsheet it can read.
     *
     * Semicolon and tab count as delimiters alongside the comma: a Romanian locale's Excel writes
     * semicolons, because the comma is the decimal separator there.
     */
    private static function looksLikeDelimitedText(string $leadingBytes): bool
    {
        if ($leadingBytes === '' || str_contains($leadingBytes, "\0")) {
            return false;
        }

        $lines = preg_split('/\r\n|\r|\n/', $leadingBytes);

        if ($lines === false || count($lines) < 2) {
            return false;
        }

        // A BOM is part of how Excel writes UTF-8 CSV and says nothing about the delimiter.
        $first = ltrim($lines[0], "\u{FEFF}");
        $second = $lines[1];

        // The window cuts the file at a fixed length, so the last line it contains may be a
        // fragment — but the first two are whole whenever there are at least three, and judging a
        // truncated second line only ever makes this stricter, never looser.
        if ($first === '' || $second === '' || ! mb_check_encoding($first . $second, 'UTF-8')) {
            return false;
        }

        foreach ([',', ';', "\t"] as $delimiter) {
            $count = self::countOutsideQuotes($first, $delimiter);

            if ($count > 0 && $count === self::countOutsideQuotes($second, $delimiter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many delimiters separate fields on this line.
     *
     * Quoted ones do not, and ignoring that would break on exactly the files this exists for: a
     * bank statement's counterparty column is full of `"Popescu, Ion"`, whose comma would make the
     * column counts disagree and the whole file unrecognisable. `""` inside a quoted field is an
     * escaped quote, per RFC 4180, and leaves the state unchanged.
     */
    private static function countOutsideQuotes(string $line, string $delimiter): int
    {
        $count = 0;
        $inQuotes = false;
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $character = $line[$index];

            if ($character === '"') {
                if ($inQuotes && ($line[$index + 1] ?? '') === '"') {
                    $index++;

                    continue;
                }

                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $character === $delimiter) {
                $count++;
            }
        }

        return $count;
    }
}
