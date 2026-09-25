<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Enums;

/**
 * Where an import has got to.
 *
 * Reading a statement is several steps with a person in the middle of them, so the state is stored
 * rather than inferred: someone uploads a file, comes back the next day, and the screen has to know
 * whether the columns were confirmed and whether the rows were saved.
 */
enum StatementImportStatus: string
{
    /** The file is being read into rows. */
    case READING = 'READING';

    /** Read; waiting for a person to confirm which column is which. */
    case MAPPING = 'MAPPING';

    /** Rows built from the confirmed mapping; waiting for a person to go through them. */
    case REVIEW = 'REVIEW';

    /** The commit job is writing transactions. */
    case IMPORTING = 'IMPORTING';

    case IMPORTED = 'IMPORTED';

    /** The file could not be read, or the mapping could not be applied. `failure_reason` says which. */
    case FAILED = 'FAILED';


    /** Whether a person can still change what this import will do. */
    public function isOpen(): bool
    {
        return $this === self::MAPPING || $this === self::REVIEW;
    }
}
