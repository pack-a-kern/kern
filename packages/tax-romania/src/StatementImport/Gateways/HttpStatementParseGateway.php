<?php

declare(strict_types=1);

namespace Kern\TaxRomania\StatementImport\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kern\TaxRomania\StatementImport\DataTransferObjects\ParsedStatementFile;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementColumn;
use Kern\TaxRomania\StatementImport\DataTransferObjects\StatementLabelGroup;
use Kern\TaxRomania\StatementImport\Enums\StatementFormat;
use Kern\TaxRomania\StatementImport\Exceptions\StatementParseUnavailableException;
use Kern\TaxRomania\StatementImport\Exceptions\StatementRejectedException;
use Throwable;

/**
 * Reads a statement file by asking the processing plane, over the internal network.
 *
 * The same shape as `HttpTextExtractionGateway`: a shared-secret bearer token, a timeout, and two
 * kinds of failure told apart because a person can only act on one of them.
 *
 *  - **The file was refused** ({@see StatementRejectedException}) — a scan with no text, a
 *    password, something that is not a statement. The reason is a closed code the screen turns into
 *    a sentence, and retrying the same file will not help.
 *  - **The reader was unreachable** ({@see StatementParseUnavailableException}) — the import stays
 *    where it is and is tried again.
 *
 * The bytes go out; nothing about them is logged here.
 */
final readonly class HttpStatementParseGateway implements StatementParseGateway
{
    public function __construct(
        private HttpFactory $http,
        private string $baseUrl,
        private int $timeoutSeconds,
        private string $authToken,
    ) {
    }

    public function parse(string $contents, ?string $sheet = null): ParsedStatementFile
    {
        return $this->toParsedFile($this->send('parse', $contents, $sheet));
    }

    public function check(string $contents): StatementFormat
    {
        $body = $this->send('check', $contents, null);

        return StatementFormat::tryFrom(is_string($body['format'] ?? null) ? $body['format'] : '')
            ?? throw StatementParseUnavailableException::malformedResponse();
    }

    /**
     * One request to the reader, its refusals and its outages told apart.
     *
     * @return array<string, mixed>
     */
    private function send(string $action, string $contents, ?string $sheet): array
    {
        if ($this->authToken === '') {
            throw StatementParseUnavailableException::authenticationNotConfigured();
        }

        $endpoint = rtrim($this->baseUrl, '/') . '/v1/statements/' . $action;

        try {
            $request = $this->http
                ->timeout($this->timeoutSeconds)
                ->withToken($this->authToken)
                // The filename is a placeholder on purpose: the reader decides the format from the
                // bytes, and the real name is a person's own and has no business in a request body.
                ->attach('file', $contents, 'statement');

            $response = $sheet === null || $sheet === ''
                ? $request->post($endpoint)
                : $request->post($endpoint, ['sheet' => $sheet]);
        } catch (ConnectionException $exception) {
            throw StatementParseUnavailableException::requestFailed($exception);
        } catch (Throwable $exception) {
            throw StatementParseUnavailableException::requestFailed($exception);
        }

        if ($response->status() === 401) {
            throw StatementParseUnavailableException::authenticationNotConfigured();
        }

        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body)) {
            throw StatementParseUnavailableException::malformedResponse();
        }

        if ($response->failed()) {
            $reason = is_string($body['error'] ?? null) ? $body['error'] : 'PARSE_FAILED';
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'The statement could not be read.';

            // A 5xx is the service failing rather than the file being wrong, and the two lead to
            // completely different screens.
            if ($response->serverError()) {
                throw StatementParseUnavailableException::malformedResponse();
            }

            throw StatementRejectedException::withReason($reason, $message);
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function toParsedFile(array $body): ParsedStatementFile
    {
        $format = StatementFormat::tryFrom(is_string($body['format'] ?? null) ? $body['format'] : '');

        if ($format === null) {
            throw StatementParseUnavailableException::malformedResponse();
        }

        $columns = [];

        foreach (is_array($body['columns'] ?? null) ? $body['columns'] : [] as $column) {
            if (! is_array($column)) {
                continue;
            }

            $columns[] = new StatementColumn(
                index: is_numeric($column['index'] ?? null) ? (int) $column['index'] : 0,
                header: is_string($column['header'] ?? null) ? $column['header'] : '',
                shape: is_string($column['shape'] ?? null) ? $column['shape'] : '',
                filledRatio: is_numeric($column['filled_ratio'] ?? null) ? (float) $column['filled_ratio'] : 0.0,
            );
        }

        $labelGroups = [];

        foreach (is_array($body['labels_by_amount_column'] ?? null) ? $body['labels_by_amount_column'] : [] as $group) {
            if (is_array($group)) {
                $labelGroups[] = StatementLabelGroup::fromArray($group);
            }
        }


        return new ParsedStatementFile(
            format: $format,
            columns: $columns,
            rows: self::grid($body['rows'] ?? null),
            headerRow: is_numeric($body['header_row'] ?? null) ? (int) $body['header_row'] : null,
            sheet: is_string($body['sheet'] ?? null) ? $body['sheet'] : null,
            sheets: array_values(array_filter(
                is_array($body['sheets'] ?? null) ? $body['sheets'] : [],
                is_string(...),
            )),
            warnings: array_values(array_filter(
                is_array($body['warnings'] ?? null) ? $body['warnings'] : [],
                is_string(...),
            )),
            labelGroups: $labelGroups,
            preamble: self::grid($body['preamble'] ?? null),
        );
    }

    /**
     * Rows of text cells, whatever the reply held.
     *
     * @return list<list<string>>
     */
    private static function grid(mixed $rows): array
    {
        $grid = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $grid[] = array_values(array_map(
                static fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '',
                is_array($row) ? $row : [],
            ));
        }

        return $grid;
    }
}
