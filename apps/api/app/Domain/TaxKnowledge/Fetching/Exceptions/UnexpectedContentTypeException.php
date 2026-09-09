<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Fetching\Exceptions;

use RuntimeException;

/**
 * `BLUEPRINT.md` §7.2 step 3 ("size/mime sanity"). This is the exact check that would have caught
 * this milestone's own investigation finding (`docs/reference/bnr-fx-endpoints.md`): every
 * documented BNR XML URL currently 302s to the HTML homepage. Expecting XML and receiving HTML is
 * a content-type/shape mismatch, not merely an HTTP-level success — this exception makes that
 * failure loud instead of silently ingesting a homepage as if it were rate data.
 */
final class UnexpectedContentTypeException extends RuntimeException
{
    public static function expectedXml(string $url, ?string $contentType): self
    {
        return new self(sprintf(
            'Expected XML content from "%s" but received Content-Type "%s" (or a non-XML body).',
            $url,
            $contentType ?? '(none)',
        ));
    }
}
