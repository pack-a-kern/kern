<?php

declare(strict_types=1);

namespace App\Domain\Banks\Parsing\Exceptions;

use RuntimeException;

/**
 * A downloaded list was not what the import can safely read.
 *
 * Thrown before anything is written: a page that changed shape or a truncated archive must stop the
 * import, not produce a short list that marks every missing bank inactive.
 */
final class InvalidBankSourceFileException extends RuntimeException
{
    public static function noDownloadLink(string $file): self
    {
        return new self("No download link for the {$file} was found on its page. The page may have changed shape.");
    }

    /** @param list<string> $columns */
    public static function missingColumns(string $file, array $columns): self
    {
        return new self(sprintf('The %s is missing the column(s): %s.', $file, implode(', ', $columns)));
    }

    public static function unreadable(string $file, string $reason): self
    {
        return new self("The {$file} could not be read: {$reason}.");
    }

    public static function tooLarge(string $file, int $bytes, int $limit): self
    {
        return new self(sprintf('The %s unpacks to %d bytes, above the %d-byte limit.', $file, $bytes, $limit));
    }

    /** @param list<string> $countries */
    public static function noInstitutions(array $countries): self
    {
        return new self(sprintf(
            'The ECB list holds no institution for %s in the configured categories — refusing to mark every known bank inactive.',
            implode(', ', $countries),
        ));
    }
}
