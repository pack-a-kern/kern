<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\Wallet;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Kern\TaxRomania\Wallet\Exports\WalletReportsCsv;
use Kern\TaxRomania\Wallet\Queries\WalletReports;
use Kern\TaxRomania\Wallet\Services\WalletAudit;

/**
 * `GET .../wallet/reports/export?from=…&to=…&part=months|breakdown|movement|largest` — the report as a CSV.
 *
 * The same period and the same query as the screen, so the file is a copy of the report rather than a
 * second answer to the same question. One part per download, because the four have different columns
 * and different readers — `WalletReportsCsv` says why.
 *
 * **Recorded in the audit chain.** The largest-payments file carries counterparties and descriptions,
 * which are encrypted at rest; everything here is already on the screen for the same reader under the
 * same permission, but a copy of it leaving the system is an event somebody may need to account for.
 */
final class ExportWalletReportsController
{
    public function __invoke(
        Request $request,
        string $subject,
        WalletReports $reports,
        WalletReportsCsv $csv,
        WalletAudit $audit,
    ): Response {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'part' => ['required', 'string', 'in:months,breakdown,movement,largest'],
        ]);

        // The same defaults as the screen's own endpoint: the current calendar year.
        $from = is_string($validated['from'] ?? null)
            ? $validated['from']
            : Carbon::now('UTC')->startOfYear()->toDateString();
        $to = is_string($validated['to'] ?? null)
            ? $validated['to']
            : Carbon::now('UTC')->endOfYear()->toDateString();
        $part = is_string($validated['part'] ?? null) ? $validated['part'] : 'months';

        $report = $reports->between($from, $to);

        $audit->record(
            subjectId: $subject,
            actorId: $this->actorId($request),
            eventType: 'wallet.reports.exported',
            resourceType: 'wallet_report',
            resourceId: "{$from}..{$to}",
            payload: ['part' => $part, 'from' => $from, 'to' => $to],
        );

        $body = match ($part) {
            'breakdown' => $csv->breakdown($report['by_tag'], $report['by_category'], $report['by_counterparty']),
            'movement' => $csv->movement($report['by_bank'], $report['by_account'], $report['totals']),
            'largest' => $csv->largest($report['largest']),
            default => $csv->months($report['cash_flow']),
        };

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"kern-wallet-{$from}-{$to}-{$part}.csv\"",
        ]);
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
