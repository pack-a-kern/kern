<?php

declare(strict_types=1);

namespace Kern\Investments\Processing;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Kern\Investments\Config\InvestmentsConfig;
use Kern\Platform\Http\StackService;
use Throwable;

/**
 * A workbook as rows of text cells, read by the processing service (`POST /v1/spreadsheets/grid`) —
 * where every file in this installation is parsed, in a disposable process with its limits, so no
 * spreadsheet library runs here (ADR 0025 §6).
 *
 * The answer is checked before it is handed on: a list of rows, each a list of text. Anything else is
 * a service not answering as it should, and nothing is read from it.
 */
final readonly class SpreadsheetGrid
{
    public function __construct(
        private HttpFactory $http,
        private ConfigRepository $config,
        private InvestmentsConfig $settings,
    ) {
    }

    /** @return list<list<string>> */
    public function rows(string $workbook): array
    {
        $service = StackService::processing($this->config);

        if (! $service->isConfigured() || ! $service->hasToken()) {
            throw SpreadsheetUnreadable::because('the processing service is not configured (PROCESSING_URL, INTERNAL_SERVICE_AUTH_TOKEN)');
        }

        try {
            $response = $service->request($this->http, $this->settings->processingTimeoutSeconds)
                // A placeholder name: the service decides the format from the bytes alone.
                ->attach('file', $workbook, 'workbook.xlsx')
                ->post($service->url('/v1/spreadsheets/grid'));
        } catch (Throwable $exception) {
            throw SpreadsheetUnreadable::because('the processing service did not answer', $exception);
        }

        $body = $response->json();

        if ($response->failed()) {
            throw SpreadsheetUnreadable::because(is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : "HTTP {$response->status()}");
        }

        $rows = is_array($body) ? ($body['rows'] ?? null) : null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw SpreadsheetUnreadable::because('the answer holds no rows');
        }

        return array_map(static function (mixed $row): array {
            if (! is_array($row) || ! array_is_list($row) || array_filter($row, is_string(...)) !== $row) {
                throw SpreadsheetUnreadable::because('a row that is not a list of text');
            }

            /** @var list<string> $row */
            return $row;
        }, $rows);
    }
}
