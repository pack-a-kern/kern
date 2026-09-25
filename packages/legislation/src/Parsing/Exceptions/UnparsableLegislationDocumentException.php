<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing\Exceptions;

use RuntimeException;

final class UnparsableLegislationDocumentException extends RuntimeException
{
    public static function emptyContent(): self
    {
        return new self('Cannot parse an empty document.');
    }

    public static function noRootElement(): self
    {
        return new self('Document HTML has no root element after parsing.');
    }
}
