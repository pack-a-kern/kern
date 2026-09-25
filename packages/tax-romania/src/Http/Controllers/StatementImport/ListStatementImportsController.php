<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Http\Controllers\StatementImport;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    private const int PER_PAGE = 25;

    public function __invoke(Request $request, string $subject): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));

        $query = StatementImport::query()->orderByDesc('created_at');
        $total = (clone $query)->count();

        /** @var list<StatementImport> $imports */
        $imports = $query->forPage($page, self::PER_PAGE)->get()->all();

        return response()->json([
            'imports' => array_map(
                static fn (StatementImport $import): array => StatementImportSummary::of($import)->toArray(),
                $imports,
            ),
            'total' => $total,
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ]);
    }
}
