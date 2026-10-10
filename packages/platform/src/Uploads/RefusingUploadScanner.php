<?php

declare(strict_types=1);

namespace Kern\Platform\Uploads;

use Kern\Platform\Uploads\Contracts\UploadScanner;
use Kern\Platform\Uploads\Exceptions\UploadRejectedException;

/**
 * The scanner an installation has when nothing has supplied one: it refuses everything.
 *
 * This is the container's default binding, so a deployment that forgets to wire a real scanner — or drops
 * `kern/documents`, which is what supplies the adapter — does not quietly start keeping unscanned bytes.
 * It stops being able to accept uploads instead, which is loud and recoverable.
 *
 * The alternative default is a scanner that passes everything, and the difference between the two is the
 * difference between an outage and a breach.
 */
final class RefusingUploadScanner implements UploadScanner
{
    public function scanOrReject($stream): void
    {
        throw UploadRejectedException::scannerUnavailable('no scanner is configured for this installation');
    }
}
