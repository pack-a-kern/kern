<?php

declare(strict_types=1);

namespace Kern\Documents\FileSecurity;

/**
 * `BLUEPRINT.md` §14.2 "sanitize filenames" / "no file execution". Strips any directory component
 * (defeats path traversal), control characters, and anything outside a safe character set, and
 * caps the length — independent of, and in addition to, `FileSecurityValidator`'s separate check
 * that the declared extension actually matches the sniffed content type.
 *
 * The sanitized name is what `documents.original_filename_ciphertext` stores — never the raw,
 * caller-supplied string. It is display metadata only; it is never used to build a storage path
 * (`Kern\Platform\ObjectStore\ObjectKey` is built only from UUIDs and a closed enum) and a stored
 * document is only ever served with a fixed, safe `Content-Disposition`, never executed.
 */
final class FilenameSanitizer
{
    private const int MAX_LENGTH = 200;

    private const string FALLBACK = 'document';

    public function sanitize(string $originalFilename): string
    {
        $basename = basename(str_replace('\\', '/', $originalFilename));

        // Strip control characters (incl. NUL) and anything not a safe display character.
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename) ?? '';
        $cleaned = preg_replace('/[^\p{L}\p{N}\s._-]/u', '', $cleaned) ?? '';
        $cleaned = trim($cleaned);

        if ($cleaned === '' || $cleaned === '.' || $cleaned === '..') {
            return self::FALLBACK;
        }

        return mb_substr($cleaned, 0, self::MAX_LENGTH);
    }
}
