<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Documents\FileSecurity\SniffedFileType;

/**
 * What a document is called on screen.
 *
 * The name a file arrives with is a poor label and often a leaky one: a phone gives every
 * photograph the same `IMG_0042.jpg`, so a list of them is a list of identical rows — and a name
 * chosen by whoever sent it can carry anything at all, from a CNP to a counterparty's full name
 * (`BLUEPRINT.md` §4.10). Neither is something to put in front of a person by default.
 *
 * So the default name is derived from the content hash the upload already computed: stable for the
 * same bytes, unique between different ones, and carrying nothing about who or what is in the file.
 *
 * It is deliberately *derived*, never stored — one source of truth, no backfill for documents that
 * predate this, and no second column to drift out of step with `sha256`.
 *
 * A person can rename a document to something meaningful, and after extraction the review screen
 * offers a suggestion built from what was actually read out of it. That renamed value is what
 * `documents.display_name_ciphertext` holds — encrypted, because a name a person chose for their
 * own invoice is exactly as revealing as the invoice.
 */
final readonly class DocumentDisplayName
{
    /** Enough to be unique in any one person's document set without becoming a wall of hex. */
    private const int HASH_LENGTH = 16;

    private const string UNKNOWN_EXTENSION = 'bin';

    public static function forContent(string $sha256, ?string $mimeType): string
    {
        // Null when the document has no files — possible only for a row mid-deletion, and an
        // extension-less name is a better answer there than a crash on a list screen.
        $extension = SniffedFileType::tryFrom($mimeType ?? '')?->allowedExtensions()[0] ?? self::UNKNOWN_EXTENSION;

        return substr($sha256, 0, self::HASH_LENGTH) . '.' . $extension;
    }
}
