<?php

declare(strict_types=1);

namespace Kern\Platform\Uploads\Contracts;

use Kern\Platform\Uploads\Exceptions\UploadRejectedException;

/**
 * Scans bytes a person uploaded, before this installation keeps them.
 *
 * ## Why a second scanning interface exists
 *
 * `Kern\Documents\Scanning\VirusScanner` is the real one, and nothing here replaces it. It cannot be
 * called from this package: `kern/documents` requires `kern/platform`, so a `use` statement pointing the
 * other way is a dependency cycle Composer would refuse. This is the port, `kern/documents` supplies the
 * adapter, and the direction stays as it was.
 *
 * It is deliberately narrower than `VirusScanner`. That one answers a question — clean or infected — because
 * the document pipeline records the verdict on `document_scans` and quarantines what fails. An avatar has
 * nowhere to sit while somebody decides: it is kept or it is refused, in one request, so the only outcome
 * worth returning is "nothing was wrong".
 *
 * ## Fail closed, always
 *
 * An implementation that cannot reach its scanner throws. It never returns. A scanner that is down must not
 * be the reason unscanned bytes were kept, and "the service was unavailable" is not a verdict.
 */
interface UploadScanner
{
    /**
     * @param resource $stream readable bytes. The caller opened it and closes it; an implementation
     *                         rewinds before reading rather than assuming a position.
     *
     * @throws UploadRejectedException if the content is infected, or the scan could not be completed
     */
    public function scanOrReject($stream): void;
}
