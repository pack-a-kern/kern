<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\StatementImport;

use App\Domain\StatementImport\Enums\RowProposalSource;
use App\Domain\StatementImport\Models\StatementImport;
use App\Domain\StatementImport\Models\StatementImportRow;
use App\Domain\StatementImport\Queries\StatementImportSummary;
use App\Domain\StatementImport\Queries\StatementRowSummary;
use App\Domain\Wallet\Services\TaxCategoryCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET .../wallet/imports/{import}` — one import, at whatever stage it has reached.
 *
 * The same endpoint answers all three screens, because they are three views of one thing: while the
 * columns are being matched it carries the preview, once they are it carries the rows, and after the
 * import it carries the counts. A screen that polls this one URL sees the state change under it
 * rather than having to know which endpoint to ask next.
 *
 * Rows are paged and narrowed by the server for the same reason the transactions list is: a
 * statement is hundreds of rows, and "show me only what is still unanswered" is the filter that
 * makes reviewing one possible at all.
 */
final class ShowStatementImportController extends Controller
{
    private const int DEFAULT_PER_PAGE = 50;

    private const int MAX_PER_PAGE = 200;

    /** What a person narrows the review to. */
    private const string FILTER_NEW = 'new';

    private const string FILTER_DUPLICATES = 'duplicates';

    private const string FILTER_UNANSWERED = 'unanswered';

    public function __invoke(
        Request $request,
        string $subject,
        string $import,
        TaxCategoryCatalogue $categories,
    ): JsonResponse {
        // Tenant-scoped: another subject's import is not found rather than shown.
        $model = StatementImport::query()->findOrFail($import);

        $validated = $request->validate([
            'filter' => ['sometimes', 'nullable', Rule::in([self::FILTER_NEW, self::FILTER_DUPLICATES, self::FILTER_UNANSWERED])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        // Read as integers: a query string is text, and `is_int('2')` is false — which served page one
        // for every page a person asked for.
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', self::DEFAULT_PER_PAGE);

        $query = StatementImportRow::query()->where('import_id', $model->id)->orderBy('row_number');

        match ($validated['filter'] ?? null) {
            self::FILTER_NEW => $query->whereNull('duplicate_transaction_id'),
            self::FILTER_DUPLICATES => $query->whereNotNull('duplicate_transaction_id'),
            // Money coming in with nothing said about it — the part of a statement that actually
            // needs a person. What went out is spending unless someone says otherwise.
            self::FILTER_UNANSWERED => $query->whereNull('category_code')->where('amount_minor', '>', 0),
            default => null,
        };

        $total = (clone $query)->count();

        /** @var list<StatementImportRow> $rows */
        $rows = $query->forPage($page, $perPage)->get()->all();

        $preview = $model->preview();

        return response()->json([
            'import' => StatementImportSummary::of($model)->toArray(),
            // The columns and sample rows the mapping screen draws. Empty once the mapping is
            // confirmed and the real rows exist — the screen has moved on by then.
            'preview' => $preview,
            'mapping' => $model->column_mapping,
            // Which fields of the form may name several places, and how many. Sent rather than
            // mirrored in the client: the rule lives in `statement_import.multi_source_fields` and
            // the ceiling in the validator, and a second copy in TypeScript would disagree with them
            // the first time either changed — by refusing a place the screen had just offered.
            'multi_source' => [
                'fields' => config()->array('statement_import.multi_source_fields'),
                'max' => config()->integer('statement_import.max_source_places'),
            ],
            // How many rows each side has answered, so the screen can say what is still waiting on
            // the model rather than leaving a person to scroll and count.
            'proposals' => [
                'ai' => StatementImportRow::query()->where('import_id', $model->id)
                    ->where('proposal_source', RowProposalSource::AI)->count(),
                'rule' => StatementImportRow::query()->where('import_id', $model->id)
                    ->where('proposal_source', RowProposalSource::RULE)->count(),
            ],
            'rows' => array_map(
                static fn (StatementImportRow $row): array => StatementRowSummary::of($row)->toArray(),
                $rows,
            ),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            // The same catalogue the review screen's selects offer and the save validates against.
            'categories' => $categories->toArray(),
        ]);
    }
}
