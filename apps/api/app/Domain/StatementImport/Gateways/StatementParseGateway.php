<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Gateways;

use App\Domain\StatementImport\DataTransferObjects\ParsedStatementFile;

/**
 * Reading a statement file into rows.
 *
 * An interface because the reader is a different service over the network, and every test that
 * exercises an import would otherwise need it running. The one implementation that talks to it is
 * {@see HttpStatementParseGateway}.
 */
interface StatementParseGateway
{
    /**
     * @param  string  $contents  the file's bytes, decrypted
     * @param  string|null  $sheet  which sheet to read, for a workbook with several
     *
     * @throws \App\Domain\StatementImport\Exceptions\StatementRejectedException the file cannot be read
     * @throws \App\Domain\StatementImport\Exceptions\StatementParseUnavailableException the reader could not be reached
     */
    public function parse(string $contents, ?string $sheet = null): ParsedStatementFile;
}
