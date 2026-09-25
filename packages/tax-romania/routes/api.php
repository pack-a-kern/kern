<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\ApproveFiscalParameterVersionController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\RejectFiscalParameterVersionController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\ShowFiscalParameterBoardController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\StartFiscalYearController;
use Kern\TaxRomania\Http\Controllers\Operations\ForgetFailedJobController;
use Kern\TaxRomania\Http\Controllers\Operations\RetryFailedJobController;
use Kern\TaxRomania\Http\Controllers\Operations\RunOperationTaskController;
use Kern\TaxRomania\Http\Controllers\Operations\ShowOperationsOverviewController;
use Kern\TaxRomania\Http\Controllers\Operations\ShowQueueJobsController;
use Kern\TaxRomania\Http\Controllers\Operations\ShowTaskRunsController;
use Kern\TaxRomania\Http\Controllers\Operations\UpdateScheduledTaskController;
use Kern\TaxRomania\Http\Controllers\Rentals\ChangeRentalRentController;
use Kern\TaxRomania\Http\Controllers\Rentals\DeleteRentalController;
use Kern\TaxRomania\Http\Controllers\Rentals\EndRentalController;
use Kern\TaxRomania\Http\Controllers\Rentals\OpenRentalController;
use Kern\TaxRomania\Http\Controllers\Rentals\ShowRentalsController;
use Kern\TaxRomania\Http\Controllers\Rentals\UpdateRentalController;
use Kern\TaxRomania\Http\Controllers\StatementImport\CommitStatementImportController;
use Kern\TaxRomania\Http\Controllers\StatementImport\DeleteStatementImportController;
use Kern\TaxRomania\Http\Controllers\StatementImport\ListStatementImportsController;
use Kern\TaxRomania\Http\Controllers\StatementImport\PreviewStatementFormController;
use Kern\TaxRomania\Http\Controllers\StatementImport\RequestColumnSuggestionController;
use Kern\TaxRomania\Http\Controllers\StatementImport\RequestRowSuggestionsController;
use Kern\TaxRomania\Http\Controllers\StatementImport\ShowStatementImportController;
use Kern\TaxRomania\Http\Controllers\StatementImport\StartStatementImportController;
use Kern\TaxRomania\Http\Controllers\StatementImport\UpdateStatementImportMappingController;
use Kern\TaxRomania\Http\Controllers\StatementImport\UpdateStatementImportRowsController;
use Kern\TaxRomania\Http\Controllers\Subjects\ChangeSubjectStatusController;
use Kern\TaxRomania\Http\Controllers\Subjects\InspectCnpController;
use Kern\TaxRomania\Http\Controllers\Subjects\ListSubjectsController;
use Kern\TaxRomania\Http\Controllers\Subjects\RegisterPfSubjectController;
use Kern\TaxRomania\Http\Controllers\Subjects\UpdatePfSubjectController;
use Kern\TaxRomania\Http\Controllers\Wallet\ClassifyTransactionController;
use Kern\TaxRomania\Http\Controllers\Wallet\DeleteAccountController;
use Kern\TaxRomania\Http\Controllers\Wallet\DeleteTagController;
use Kern\TaxRomania\Http\Controllers\Wallet\DeleteTransactionController;
use Kern\TaxRomania\Http\Controllers\Wallet\KeepDuplicateTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\MergeDuplicateTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\MergeTagsController;
use Kern\TaxRomania\Http\Controllers\Wallet\OpenAccountController;
use Kern\TaxRomania\Http\Controllers\Wallet\RecordTransactionController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowAccountsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowBanksController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowDuplicateTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowTagsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowTaxCategoriesController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowWalletOverviewController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowWalletReportsController;
use Kern\TaxRomania\Http\Controllers\Wallet\UpdateAccountController;
use Kern\TaxRomania\Http\Controllers\Wallet\UpdateDisplayCurrencyController;
use Kern\TaxRomania\Http\Controllers\Wallet\UpdateTagController;
use Kern\TaxRomania\Http\Controllers\Wallet\UpdateTransactionController;

/*
|--------------------------------------------------------------------------
| TaxOS România — JSON routes
|--------------------------------------------------------------------------
|
| Loaded by `Kern\TaxRomania\TaxRomaniaServiceProvider` at `config('kern.routing')`. The taxpayer
| group spells `v1/subjects/{subject}` out in full rather than asking for the tenant prefix: this
| package is where a tenant *is* a taxpayer, so it is the one place the word belongs.
*/

Route::prefix('v1/subjects')->middleware('auth:web')->group(function (): void {
    Route::get('/', ListSubjectsController::class);
    Route::post('pf', RegisterPfSubjectController::class);
    // Reads a CNP and answers what it contains — including whether it is already registered, which
    // is what makes the throttle matter: the endpoint writes nothing, but answered without limit it
    // would be a way of testing CNPs against this installation. Registration refuses a duplicate in
    // any case, so this only changes how cheaply the same answer can be asked for.
    Route::post('cnp', InspectCnpController::class)->middleware('throttle:20,1');
    // Outside the `tax-subject` group below: these act *on* a taxpayer rather than within one, and
    // binding a tenant context to change that tenant's own status reads the wrong way round. Both
    // check ownership themselves.
    Route::patch('{subject}', UpdatePfSubjectController::class);
    Route::put('{subject}/status', ChangeSubjectStatusController::class);
});

/*
 * `docs/handoff/022-document-pipeline.md` — Milestone 7. `tax-subject` binds
 * `Kern\Platform\Tenancy\TenantContext` from `{subject}` (docs/handoff/011-identity-
 * tenancy.md step 2) before any controller runs; every business query below is tenant-scoped
 * automatically from that point on.
 */
Route::prefix('v1/subjects/{subject}')->middleware(['auth:web', 'tax-subject'])->group(function (): void {
    /*
     * Wallet (`BLUEPRINT.md` §6.4) — accounts, deposits and their totals for the active tax
     * subject.
     *
     * The writes are thin over `Kern\TaxRomania\Wallet\Actions\*`, which own the encryption of the
     * display name and IBAN and the rate-coverage rule on a deposit. Nothing is reimplemented in the
     * HTTP layer, so a record created from the screen is the same shape as one a future import
     * produces.
     *
     * Inside the `{subject}` group deliberately: every Wallet model is `ScopedToTenant`, so
     * these rows only exist relative to one subject and the `tax-subject` middleware binds it
     * before any query runs.
     */
    Route::get('wallet/overview', ShowWalletOverviewController::class);
    // A year read three ways — by tag, by bank, by category. Never a tax figure: what is owed comes
    // from the tax engine's versioned rules, not from a person's own labels.
    Route::get('wallet/reports', ShowWalletReportsController::class);
    // Which currency this subject's totals are added up in. A display preference — never a fiscal
    // conversion, which each tax rule decides for itself with its own provenance.
    Route::put('wallet/display-currency', UpdateDisplayCurrencyController::class);
    Route::get('wallet/accounts', ShowAccountsController::class);
    // One endpoint for every kind of account. A TERM_DEPOSIT carries its deposit terms in the same
    // request — opening a deposit also computes its full interest schedule, since everything the
    // calculation needs is fixed at that moment.
    Route::post('wallet/accounts', OpenAccountController::class);
    // Editing is a PATCH of only what changed; deleting is refused while anything still explains a
    // figure through the account, which is what `closed_at` is for instead. Every mutation writes an
    // audit event — an edit nobody recorded is indistinguishable from one that never happened.
    Route::patch('wallet/accounts/{account}', UpdateAccountController::class)->where('account', '[0-9a-f-]{36}');
    Route::delete('wallet/accounts/{account}', DeleteAccountController::class)->where('account', '[0-9a-f-]{36}');

    // Rentals: what a contract owes, per year. A rental never creates a transaction — see `RentalQuery`.
    Route::get('rentals', ShowRentalsController::class);
    Route::post('rentals', OpenRentalController::class);
    Route::patch('rentals/{rental}', UpdateRentalController::class)->where('rental', '[0-9a-f-]{36}');
    Route::delete('rentals/{rental}', DeleteRentalController::class)->where('rental', '[0-9a-f-]{36}');
    Route::post('rentals/{rental}/rent', ChangeRentalRentController::class)->where('rental', '[0-9a-f-]{36}');
    Route::post('rentals/{rental}/end', EndRentalController::class)->where('rental', '[0-9a-f-]{36}');
    Route::get('wallet/categories', ShowTaxCategoriesController::class);
    /*
     * A person's own labels for their spending — not a tax vocabulary, so unlike categories these
     * are rows a person creates, renames and merges rather than a config file.
     */
    Route::get('wallet/tags', ShowTagsController::class);
    Route::patch('wallet/tags/{tag}', UpdateTagController::class)->where('tag', '[0-9a-f-]{36}');
    Route::post('wallet/tags/{tag}/merge', MergeTagsController::class)->where('tag', '[0-9a-f-]{36}');
    Route::delete('wallet/tags/{tag}', DeleteTagController::class)->where('tag', '[0-9a-f-]{36}');
    Route::get('wallet/banks', ShowBanksController::class);
    /*
     * Importing a bank's own statement (`docs/handoff/045-statement-import.md`).
     *
     * The file is uploaded once and then answered in steps — columns, then rows — so an import is a
     * resource with a state rather than one request: `POST` starts it, `GET` follows it through every
     * stage, and the last two write to the ledger and take it back out again.
     */
    Route::get('wallet/imports', ListStatementImportsController::class);
    Route::post('wallet/imports', StartStatementImportController::class)
        ->middleware('throttle:documents-upload-per-subject');
    Route::get('wallet/imports/{import}', ShowStatementImportController::class)->where('import', '[0-9a-f-]{36}');
    Route::delete('wallet/imports/{import}', DeleteStatementImportController::class)
        ->where('import', '[0-9a-f-]{36}');
    // Read-only: what a form would make of the sample rows, while a person is still filling it in.
    Route::post('wallet/imports/{import}/mapping/preview', PreviewStatementFormController::class)
        ->where('import', '[0-9a-f-]{36}');
    Route::put('wallet/imports/{import}/mapping', UpdateStatementImportMappingController::class)
        ->where('import', '[0-9a-f-]{36}');
    // Asking the model again — for the form, or for the rows still unanswered — without re-uploading.
    Route::post('wallet/imports/{import}/mapping/suggest', RequestColumnSuggestionController::class)
        ->where('import', '[0-9a-f-]{36}');
    Route::post('wallet/imports/{import}/suggestions', RequestRowSuggestionsController::class)
        ->where('import', '[0-9a-f-]{36}');
    Route::patch('wallet/imports/{import}/rows', UpdateStatementImportRowsController::class)
        ->where('import', '[0-9a-f-]{36}');
    Route::post('wallet/imports/{import}/commit', CommitStatementImportController::class)
        ->where('import', '[0-9a-f-]{36}');

    Route::get('wallet/transactions', ShowTransactionsController::class);
    // Lookalikes: gathered for a person, never refused at import. See `DuplicateTransactionGroups`.
    Route::get('wallet/transactions/duplicates', ShowDuplicateTransactionsController::class);
    Route::post('wallet/transactions/duplicates/merge', MergeDuplicateTransactionsController::class);
    Route::post('wallet/transactions/duplicates/keep', KeepDuplicateTransactionsController::class);
    Route::post('wallet/transactions', RecordTransactionController::class);
    Route::patch('wallet/transactions/{transaction}', UpdateTransactionController::class)->where('transaction', '[0-9a-f-]{36}');
    Route::delete('wallet/transactions/{transaction}', DeleteTransactionController::class)->where('transaction', '[0-9a-f-]{36}');
    // Saying what a transaction *was*. A PUT because it replaces: correcting a label means sending
    // the splits again, and an empty list returns the row to the unclassified worklist.
    Route::put('wallet/transactions/{transaction}/splits', ClassifyTransactionController::class)
        ->where('transaction', '[0-9a-f-]{36}');
});

// The values a tax is computed from, as read from the official texts: seen by those who may view
// them, decided — approved, rejected, a new year proposed — by those who may approve them. Reading
// them is the `fiscal.parameters.read` task, run through the operations routes.
Route::prefix('v1/fiscal-parameters')->middleware('auth:web')->group(function (): void {
    Route::get('/', ShowFiscalParameterBoardController::class)->middleware('permission:fiscal.parameters.view');

    Route::middleware('permission:fiscal.parameters.approve')->group(function (): void {
        Route::post('versions/{version}/approve', ApproveFiscalParameterVersionController::class)->whereUuid('version');
        Route::post('versions/{version}/reject', RejectFiscalParameterVersionController::class)->whereUuid('version');
        Route::post('years', StartFiscalYearController::class);
    });
});

/*
 * Background operations — the Tasks and Jobs tabs of `/logs`.
 *
 * Two permissions, not one, and the split is deliberate: reading whether the nightly import
 * succeeded is something a support engineer needs, while starting an import, pausing the schedule,
 * or retrying a failed job both *change what the system does* and belong to a smaller
 * group. Both are distinct from `system.settings.manage`: "restart the
 * importer" and "change where taxpayer documents are sent for AI processing" are not the same
 * level of trust, and one permission covering both would grant the second to everyone who needs
 * the first.
 */
Route::prefix('v1/operations')->middleware(['auth:web'])->group(function (): void {
    Route::middleware('permission:operations.view')->group(function (): void {
        // Two listings rather than one feed, because they are two populations that never overlap:
        // a task reaches the queue wrapped in `RunOperationTask` and records its own outcome, so it
        // never appears among the failures, and no queued job ever appears in the run ledger.
        Route::get('/', ShowOperationsOverviewController::class);
        Route::get('tasks/{task}/runs', ShowTaskRunsController::class)->where('task', '[a-z_.]+');
        Route::get('jobs', ShowQueueJobsController::class);
    });

    Route::middleware('permission:operations.run')->group(function (): void {
        // `{task}` and `{code}` are resolved against a closed enum and an existing registry row
        // respectively, inside the controllers — the `where` clauses below only keep obviously
        // malformed paths from reaching them.
        Route::post('tasks/{task}/run', RunOperationTaskController::class)
            ->where('task', '[a-z_.]+');
        Route::put('tasks/{task}', UpdateScheduledTaskController::class)
            ->where('task', '[a-z_.]+');

        Route::post('failed-jobs/{uuid}/retry', RetryFailedJobController::class);
        Route::delete('failed-jobs/{uuid}', ForgetFailedJobController::class);

        // No route writes the official-source allowlist, deliberately. It is declared in
        // `config/sources.php`, and `Kern\Legislation\Repositories\
        // OfficialSourceRepository::resolve()` upserts `ext_official_sources` from that config on every
        // fetch — so a value written here would survive only until the next import, while
        // `AllowlistedHttpFetcher` never consulted the row in the first place. The screen renders
        // the allowlist read-only rather than offering a switch that reports a state it cannot hold.
    });
});
