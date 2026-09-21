<?php

declare(strict_types=1);

namespace App\Domain\StatementImport\Gateways;

use App\Domain\StatementImport\DataTransferObjects\ParsedStatementFile;
use App\Domain\StatementImport\Enums\StatementFormat;

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

    /**
     * Whether the file could be read, refused for the same reasons {@see parse()} would — and its
     * format. Asked before a PDF is sent out to be converted, so a scan is still refused here.
     *
     * @throws \App\Domain\StatementImport\Exceptions\StatementRejectedException the file cannot be read
     * @throws \App\Domain\StatementImport\Exceptions\StatementParseUnavailableException the reader could not be reached
     */
    public function check(string $contents): StatementFormat;
}
