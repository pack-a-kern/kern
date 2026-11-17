<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\TaxRomania\Banks\Logos\BankLogos;
use Kern\TaxRomania\Http\Controllers\Banks\RefreshBankLogosController;
use Kern\TaxRomania\Http\Controllers\Banks\ShowBankLogoController;
use Kern\TaxRomania\Http\Controllers\Banks\ShowBankLogosController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\ApproveFiscalParameterVersionController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\DropFiscalParameterValuesController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\ReadMinimumWagesController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\RejectFiscalParameterVersionController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\ShowFiscalParameterBoardController;
use Kern\TaxRomania\Http\Controllers\FiscalParameters\StartFiscalYearController;
use Kern\TaxRomania\Http\Controllers\Fx\ShowCurrencyCatalogueController;
use Kern\TaxRomania\Http\Controllers\Fx\ShowFxRateHistoryController;
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
use Kern\TaxRomania\Http\Controllers\Spv\DeleteSpvConnectionController;
use Kern\TaxRomania\Http\Controllers\Spv\DestroySpvSessionController;
use Kern\TaxRomania\Http\Controllers\Spv\RegisterSpvConnectionController;
use Kern\TaxRomania\Http\Controllers\Spv\RevokeSpvConnectionController;
use Kern\TaxRomania\Http\Controllers\Spv\ShowSpvConnectionsController;
use Kern\TaxRomania\Http\Controllers\Spv\StartSpvSignInController;
use Kern\TaxRomania\Http\Controllers\Spv\SubmitSpvChallengeCodeController;
use Kern\TaxRomania\Http\Controllers\Spv\SubmitSpvCredentialsController;
use Kern\TaxRomania\Http\Controllers\Spv\SyncSpvInboxController;
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
use Kern\TaxRomania\Http\Controllers\Subjects\ShowSubjectAvatarController;
use Kern\TaxRomania\Http\Controllers\Subjects\ShowSubjectCountsController;
use Kern\TaxRomania\Http\Controllers\Subjects\UpdatePfSubjectController;
use Kern\TaxRomania\Http\Controllers\Subjects\UpdateSubjectAvatarController;
use Kern\TaxRomania\Http\Controllers\TaxEngine\AssessFiscalYearController;
use Kern\TaxRomania\Http\Controllers\TaxEngine\ExportAssessmentController;
use Kern\TaxRomania\Http\Controllers\TaxEngine\FinaliseFiscalYearController;
use Kern\TaxRomania\Http\Controllers\TaxEngine\ShowFiscalYearAssessmentController;
use Kern\TaxRomania\Http\Controllers\TaxEngine\UnlockFiscalYearController;
use Kern\TaxRomania\Http\Controllers\Wallet\AddHoldingController;
use Kern\TaxRomania\Http\Controllers\Wallet\ClassifyTransactionController;
use Kern\TaxRomania\Http\Controllers\Wallet\DeleteAccountController;
use Kern\TaxRomania\Http\Controllers\Wallet\DeleteTagController;
use Kern\TaxRomania\Http\Controllers\Wallet\DeleteTransactionController;
use Kern\TaxRomania\Http\Controllers\Wallet\ExportWalletReportsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ForgetCounterpartiesController;
use Kern\TaxRomania\Http\Controllers\Wallet\KeepDuplicateTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\MergeCounterpartiesController;
use Kern\TaxRomania\Http\Controllers\Wallet\MergeDuplicateTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\MergeTagsController;
use Kern\TaxRomania\Http\Controllers\Wallet\OpenAccountController;
use Kern\TaxRomania\Http\Controllers\Wallet\OpenDepositsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ReadDepositCsvController;
use Kern\TaxRomania\Http\Controllers\Wallet\RecordTransactionController;
use Kern\TaxRomania\Http\Controllers\Wallet\SellHoldingController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowAccountsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowBanksController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowCounterpartiesController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowCounterpartySuggestionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowDepositTermController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowDuplicateTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowHoldingsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowTagsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowTaxCategoriesController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowTransactionsController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowWalletOverviewController;
use Kern\TaxRomania\Http\Controllers\Wallet\ShowWalletReportsController;
use Kern\TaxRomania\Http\Controllers\Wallet\UpdateAccountController;
use Kern\TaxRomania\Http\Controllers\Wallet\UpdateCounterpartyController;
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
    Route::get('counts', ShowSubjectCountsController::class);
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
    /*
     * A taxpayer's picture, here for the same reason the two above are: these act *on* a taxpayer
     * rather than within one, so binding a tenant context to change that tenant's own card reads the
     * wrong way round. Both controllers check membership themselves — reading needs any live
     * membership, writing needs the owner, exactly as the status change does.
     */
    Route::get('{subject}/avatar', ShowSubjectAvatarController::class);
    Route::post('{subject}/avatar', [UpdateSubjectAvatarController::class, 'store']);
    Route::delete('{subject}/avatar', [UpdateSubjectAvatarController::class, 'destroy']);
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
    // The same report as a file, one panel per download. Before any `wallet/reports/{…}` would be —
    // there is none today, and a literal segment that loses to a parameter is a bug waiting for one.
    Route::get('wallet/reports/export', ExportWalletReportsController::class);
    // Which currency this subject's totals are added up in. A display preference — never a fiscal
    // conversion, which each tax rule decides for itself with its own provenance.
    Route::put('wallet/display-currency', UpdateDisplayCurrencyController::class);
    Route::get('wallet/accounts', ShowAccountsController::class);
    // One endpoint for every kind of account. A TERM_DEPOSIT carries its deposit terms in the same
    // request — opening a deposit also computes its full interest schedule, since everything the
    // calculation needs is fixed at that moment.
    Route::post('wallet/accounts', OpenAccountController::class);
    // Term deposits in bulk, from a CSV: the file is read and dropped, never stored, and the deposits
    // are then opened by the same action and the same rules as the form — all of them, or none.
    Route::post('wallet/deposits/csv', ReadDepositCsvController::class);
    Route::post('wallet/deposits', OpenDepositsController::class);
    // What a term makes of a start — the maturity and its days — for the form while it is filled in.
    Route::get('wallet/deposits/term', ShowDepositTermController::class);
    // Editing is a PATCH of only what changed; deleting is refused while anything still explains a
    // figure through the account, which is what `closed_at` is for instead. Every mutation writes an
    // audit event — an edit nobody recorded is indistinguishable from one that never happened.
    Route::patch('wallet/accounts/{account}', UpdateAccountController::class)->where('account', '[0-9a-f-]{36}');
    // Past undoing: asked again for the password or code (`verify-recent`).
    Route::delete('wallet/accounts/{account}', DeleteAccountController::class)->where('account', '[0-9a-f-]{36}')->middleware('verify-recent');
    // Bonds and shares in an investments account, one lot per purchase (ADR 0025 §9). A lot is added and
    // sold; its coupons, dividends and repayment are computed from its instrument, never entered.
    Route::get('wallet/holdings', ShowHoldingsController::class);
    Route::post('wallet/holdings', AddHoldingController::class);
    // A sale records a taxable gain and what was withheld from it: asked again for the password or code.
    Route::post('wallet/holdings/sales', SellHoldingController::class)->middleware('verify-recent');

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
    Route::get('wallet/counterparties', ShowCounterpartiesController::class);
    Route::get('wallet/counterparties/suggestions', ShowCounterpartySuggestionsController::class);
    Route::post('wallet/counterparties/merge', MergeCounterpartiesController::class);
    Route::post('wallet/counterparties/forget', ForgetCounterpartiesController::class);
    Route::patch('wallet/counterparties/{counterparty}', UpdateCounterpartyController::class)
        ->where('counterparty', '[0-9a-f-]{36}');
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
    // One budget for both, per taxpayer: each is a round of calls to a provider that may bill for them.
    Route::post('wallet/imports/{import}/mapping/suggest', RequestColumnSuggestionController::class)
        ->where('import', '[0-9a-f-]{36}')
        ->middleware('throttle:statement-import-model-per-subject');
    Route::post('wallet/imports/{import}/suggestions', RequestRowSuggestionsController::class)
        ->where('import', '[0-9a-f-]{36}')
        ->middleware('throttle:statement-import-model-per-subject');
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

    /*
     * SPV — the taxpayer's inbox at ANAF.
     *
     * A connection records how this taxpayer signs in; it is never a saved login. The three session
     * routes are one sitting a person spends in front of the screen: open a browser, send the
     * credentials, send the code ANAF emails. All synchronous, because a queued job would serialise
     * a password into Redis and the whole point of `SpvCredentialPolicy` is that nothing does.
     *
     * A certificate connection uses none of the session routes: the certificate authenticates each
     * request on its own, so such a connection goes straight to sync.
     */
    /*
     * `verify-recent` marks the acts, not the reads.
     *
     * §4.7 wants MFA of an account that reaches the tax authority. It used to be checked inside two
     * actions, as enrolment — which anybody holding the session passes without owning a factor, and
     * which `sync` did not consult at all, although reading the inbox is the thing worth protecting.
     * The middleware demands a code, recently, on every route that acts.
     */
    Route::get('spv/connections', ShowSpvConnectionsController::class);
    Route::post('spv/connections', RegisterSpvConnectionController::class)
        ->middleware('verify-recent');
    Route::delete('spv/connections/{connection}', DeleteSpvConnectionController::class)
        ->middleware('verify-recent')
        ->where('connection', '[0-9a-f-]{36}');
    Route::post('spv/connections/{connection}/revocation', RevokeSpvConnectionController::class)
        ->middleware('verify-recent')
        ->where('connection', '[0-9a-f-]{36}');
    Route::post('spv/connections/{connection}/session', StartSpvSignInController::class)
        ->middleware('verify-recent')
        ->where('connection', '[0-9a-f-]{36}');
    Route::post('spv/connections/{connection}/sync', SyncSpvInboxController::class)
        ->middleware('verify-recent')
        ->where('connection', '[0-9a-f-]{36}');
    /*
     * Not marked: both are steps of the sign-in that `.../session` opened moments ago, inside the
     * same window, and the challenge code is itself a factor arriving from ANAF. Demanding a second
     * code between typing a password and typing the emailed one would be friction with nothing
     * behind it.
     */
    Route::post('spv/sessions/{session}/credentials', SubmitSpvCredentialsController::class)
        ->where('session', '[0-9a-f-]{36}');
    Route::post('spv/sessions/{session}/challenge', SubmitSpvChallengeCodeController::class)
        ->where('session', '[0-9a-f-]{36}');
    Route::delete('spv/sessions/{session}', DestroySpvSessionController::class)
        ->where('session', '[0-9a-f-]{36}');
});

// The values a tax is computed from, as read from the official texts: seen by those who may view
// them, decided — approved, rejected, a new year proposed — by those who may approve them. Reading
// them is the `fiscal.parameters.read` task, run through the operations routes.
/*
 * Computing a draft assessment for a fiscal year.
 *
 * Inside the tenant-scoped group's shape — `{subject}` is the taxpayer whose ledger is read — and behind
 * `verify-recent`: an assessment reads a year of somebody's finances and writes figures they may file, which
 * is the kind of act that gate exists for.
 */
Route::prefix('v1/subjects/{subject}')->middleware(['auth:web', 'tax-subject'])->group(function (): void {
    /*
     * Reading the year's assessment back needs no step-up: it returns figures already recorded, which the
     * taxpayer's own screens show anyway. Computing one does, because it writes.
     */
    Route::get('tax/assessments', ShowFiscalYearAssessmentController::class)->whereUuid('subject');

    /*
     * The same figures as a file. No step-up: it returns what the screen above already shows, and nothing in
     * it is decrypted — the evidence names splits by id rather than carrying counterparties. The download is
     * recorded in the audit chain regardless.
     */
    Route::get('tax/assessments/export', ExportAssessmentController::class)->whereUuid('subject');

    Route::post('tax/assessments', AssessFiscalYearController::class)
        ->middleware('verify-recent')
        ->whereUuid('subject');

    /*
     * Marking a year final and taking it back. Both behind `verify-recent`: the first locks a year's
     * transactions against every path that could change them, and the second unlocks them again.
     */
    Route::post('tax/assessments/final', FinaliseFiscalYearController::class)
        ->middleware('verify-recent')
        ->whereUuid('subject');

    Route::post('tax/assessments/unlock', UnlockFiscalYearController::class)
        ->middleware('verify-recent')
        ->whereUuid('subject');
});

/*
 * Published reference rates, which belong to no taxpayer: behind `auth:web` so a stranger cannot read them
 * from this installation, and outside `{subject}` because scoping them would imply they differ per subject.
 */
Route::prefix('v1/fx')->middleware('auth:web')->group(function (): void {
    Route::get('rates/history', ShowFxRateHistoryController::class);
    // What the settings screen offers and what it saves. Not behind a permission: knowing that BNR
    // publishes a yen rate tells a reader nothing about this installation's taxpayers.
    Route::get('currencies', ShowCurrencyCatalogueController::class);
});

Route::prefix('v1/fiscal-parameters')->middleware('auth:web')->group(function (): void {
    Route::get('/', ShowFiscalParameterBoardController::class)->middleware('permission:fiscal.parameters.view');

    Route::middleware('permission:fiscal.parameters.approve')->group(function (): void {
        Route::post('versions/{version}/approve', ApproveFiscalParameterVersionController::class)->whereUuid('version');
        Route::post('versions/{version}/reject', RejectFiscalParameterVersionController::class)->whereUuid('version');
        Route::post('years', StartFiscalYearController::class);
        // Queues a scan of every law already read for the minimum wage — minutes of work on a worker —
        // so it is rate-limited per person, as the other operator calls that start queue work are.
        Route::post('minimum-wage', ReadMinimumWagesController::class)->middleware('throttle:operator-calls-per-user');
        Route::delete('values', DropFiscalParameterValuesController::class);
    });
});

/*
 * Bank logos — `Kern\TaxRomania\Banks\Logos\BankLogos`.
 *
 * The logo itself is for anyone signed in, as the bank list it belongs to is; the file is found by
 * the bank, never from the address. The folder's state is read with the settings it belongs to, and
 * "Refresh" — which reads files and changes nothing but the cache — needs the right to manage them.
 */
Route::prefix('v1')->middleware(['auth:web'])->group(function (): void {
    Route::get('banks/{bank}/logo', ShowBankLogoController::class)->whereUuid('bank')->name(BankLogos::ROUTE);

    Route::get('settings/banks/logos', ShowBankLogosController::class)->middleware('permission:system.settings.view');
    Route::post('settings/banks/logos/refresh', RefreshBankLogosController::class)
        ->middleware(['permission:system.settings.manage', 'throttle:operator-calls-per-user']);
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
            ->where('task', '[a-z_.]+')
            ->middleware('throttle:operator-calls-per-user');
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
