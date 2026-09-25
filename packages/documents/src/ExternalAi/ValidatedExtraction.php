<?php

declare(strict_types=1);

namespace Kern\Documents\ExternalAi;

use Kern\Documents\Enums\DocumentType;

/**
 * A reading that satisfied a schema, and the kind of document it turned out to be.
 *
 * The two travel together because they were decided together: a document arrives untyped, and the
 * same reply that gives the fields is what says whether those fields describe an invoice or a
 * statement. Returning only the fields would leave the caller to ask a second time, of something
 * that never read the document.
 */
final readonly class ValidatedExtraction
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public DocumentType $type,
        public array $fields,
    ) {
    }
}
