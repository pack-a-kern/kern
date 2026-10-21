<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Enums;

/**
 * What a statement file turned out to be — decided by the reader from the bytes, never from the
 * name a browser sent.
 */
enum StatementFormat: string
{
    case CSV = 'CSV';
    case XLSX = 'XLSX';
    case XLS = 'XLS';
    case PDF = 'PDF';
}
