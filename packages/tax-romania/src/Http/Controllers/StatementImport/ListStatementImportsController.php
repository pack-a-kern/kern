<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kern\Platform\Routing\PageSizes;
use Kern\TaxRomania\StatementImport\Models\StatementImport;
use Kern\TaxRomania\StatementImport\Queries\StatementImportSummary;

/**
 * `GET .../wallet/imports` — the statements this taxpayer has imported, newest first.
 *
 * The history exists for one thing above all: undoing. A person who imported the wrong month needs
 * to find that import, see what it did, and take it back out.
 */
final class ListStatementImportsController
{
    public function __invoke(Request $request, string $subject, PageSizes $sizes): JsonResponse
    {
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => $sizes->rule()]);
        $page = isset($validated['page']) ? max(1, (int) $validated['page']) : 1;
        $perPage = $sizes->of($validated['per_page'] ?? null);

        $query = StatementImport::query()->orderByDesc('created_at');
        $total = (clone $query)->count();

        /** @var list<StatementImport> $imports */
        $imports = $query->forPage($page, $perPage)->get()->all();

        return response()->json([
            'imports' => array_map(
                static fn (StatementImport $import): array => StatementImportSummary::of($import)->toArray(),
                $imports,
            ),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }
}
