<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Declarations\Exceptions;

use RuntimeException;

/**
 * A `RECTIFICATIVE` may only correct a declaration that was actually filed
 * (`SUBMITTED|ACCEPTED|REJECTED`) — correcting a `DRAFT`/`VALIDATING`/`VALID` row makes no sense:
 * that row can simply still be edited/re-validated directly, since nothing has been sent yet.
 */
final class RectificativeSourceNotFiledException extends RuntimeException
{
    public static function forDeclaration(string $declarationId, string $status): self
    {
        return new self(
            "Declaration {$declarationId} has status {$status}; only a filed declaration " .
            '(SUBMITTED, ACCEPTED, or REJECTED) can be corrected by a RECTIFICATIVE.',
        );
    }
}
