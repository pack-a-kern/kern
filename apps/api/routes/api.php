<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Account\ShowProfileController;
use App\Http\Controllers\Api\V1\Account\UpdatePasswordController;
use App\Http\Controllers\Api\V1\Admin\ShowAccessCatalogueController;
use App\Http\Controllers\Api\V1\Admin\ShowUsersController;
use App\Http\Controllers\Api\V1\Admin\UpdateUserRolesController;
use App\Http\Controllers\Api\V1\Admin\UpdateUserStatusController;
use App\Http\Controllers\Api\V1\Audit\ShowAuditEventsController;
use App\Http\Controllers\Api\V1\Audit\ShowAuditSummaryController;
use App\Http\Controllers\Api\V1\Auth\DisableTotpController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MfaChallengeController;
use App\Http\Controllers\Api\V1\Auth\MfaStatusController;
use App\Http\Controllers\Api\V1\Auth\PasskeyLoginController;
use App\Http\Controllers\Api\V1\Auth\PasskeyRegistrationController;
use App\Http\Controllers\Api\V1\Auth\RecoveryCodeController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TotpController;
use App\Http\Controllers\Api\V1\Documents\AcceptDocumentExtractionController;
use App\Http\Controllers\Api\V1\Documents\CorrectDocumentExtractionController;
use App\Http\Controllers\Api\V1\Documents\CreateTransactionFromDocumentController;
use App\Http\Controllers\Api\V1\Documents\DeleteDocumentController;
use App\Http\Controllers\Api\V1\Documents\DocumentAiConsentController;
use App\Http\Controllers\Api\V1\Documents\ListDocumentExtractionsController;
use App\Http\Controllers\Api\V1\Documents\ListDocumentsController;
use App\Http\Controllers\Api\V1\Documents\RenameDocumentController;
use App\Http\Controllers\Api\V1\Documents\RetryDocumentExtractionController;
use App\Http\Controllers\Api\V1\Documents\ShowDocumentAiConsentsController;
use App\Http\Controllers\Api\V1\Documents\ShowDocumentContentController;
use App\Http\Controllers\Api\V1\Documents\ShowDocumentController;
use App\Http\Controllers\Api\V1\Documents\ShowDocumentHistoryController;
use App\Http\Controllers\Api\V1\Documents\UploadDocumentController;
use App\Http\Controllers\Api\V1\Legislation\SearchLegislationController;
use App\Http\Controllers\Api\V1\Operations\ForgetFailedJobController;
use App\Http\Controllers\Api\V1\Operations\RetryFailedJobController;
use App\Http\Controllers\Api\V1\Operations\RunOperationTaskController;
use App\Http\Controllers\Api\V1\Operations\ShowOperationsActivityController;
use App\Http\Controllers\Api\V1\Operations\ShowOperationsOverviewController;
use App\Http\Controllers\Api\V1\Operations\UpdateScheduledTaskController;
use App\Http\Controllers\Api\V1\Settings\ClearAiApiKeyController;
use App\Http\Controllers\Api\V1\Settings\ListAiModelsController;
use App\Http\Controllers\Api\V1\Settings\ShowAiProvidersController;
use App\Http\Controllers\Api\V1\Settings\ShowAiSettingsController;
use App\Http\Controllers\Api\V1\Settings\ShowSettingsController;
use App\Http\Controllers\Api\V1\Settings\ShowSystemHealthController;
use App\Http\Controllers\Api\V1\Settings\TestAiConnectionController;
use App\Http\Controllers\Api\V1\Settings\UpdateAiSettingsController;
use App\Http\Controllers\Api\V1\Settings\UpdateSettingsGroupController;
use App\Http\Controllers\Api\V1\Subjects\ListSubjectsController;
use App\Http\Controllers\Api\V1\Subjects\RegisterPfSubjectController;
use App\Http\Controllers\Api\V1\Wallet\DeleteAccountController;
use App\Http\Controllers\Api\V1\Wallet\DeleteTransactionController;
use App\Http\Controllers\Api\V1\Wallet\OpenAccountController;
use App\Http\Controllers\Api\V1\Wallet\RecordBalanceController;
use App\Http\Controllers\Api\V1\Wallet\RecordTransactionController;
use App\Http\Controllers\Api\V1\Wallet\ShowAccountsController;
use App\Http\Controllers\Api\V1\Wallet\ShowTransactionsController;
use App\Http\Controllers\Api\V1\Wallet\ShowWalletOverviewController;
use App\Http\Controllers\Api\V1\Wallet\UpdateAccountController;
use App\Http\Controllers\Api\V1\Wallet\UpdateDisplayCurrencyController;
use App\Http\Controllers\Api\V1\Wallet\UpdateTransactionController;
use Illuminate\Support\Facades\Route;

/*
 * `docs/handoff/017-authentication.md` documents the full lifecycle these routes implement.
 * Every unauthenticated authentication endpoint carries a named rate limiter
 * (`App\Domain\Identity\IdentityServiceProvider::boot()`, `BLUEPRINT.md` §4.7) — none rely on the
 * framework's default unkeyed `throttle:60,1`.
 */
Route::prefix('v1/auth')->group(function (): void {
    Route::post('register', RegistrationController::class)
        ->middleware('throttle:register-per-ip');

    Route::post('login', LoginController::class)
        ->middleware(['throttle:login-per-account-ip', 'throttle:login-per-ip']);

    Route::post('mfa/challenge', MfaChallengeController::class)
        ->middleware('throttle:mfa-challenge-per-user');

    Route::post('passkeys/login/options', [PasskeyLoginController::class, 'options'])
        ->middleware('throttle:passkey-ceremony-per-ip');
    Route::post('passkeys/login', [PasskeyLoginController::class, 'store'])
        ->middleware('throttle:passkey-ceremony-per-ip');

    Route::middleware('auth:web')->group(function (): void {
        Route::post('logout', LogoutController::class);

        Route::post('passkeys/registration/options', [PasskeyRegistrationController::class, 'options'])
            ->middleware('throttle:passkey-ceremony-per-ip');
        Route::post('passkeys/registration', [PasskeyRegistrationController::class, 'store'])
            ->middleware('throttle:passkey-ceremony-per-ip');

        Route::get('mfa/status', MfaStatusController::class);
        Route::post('mfa/totp/enroll', [TotpController::class, 'enroll']);
        Route::post('mfa/totp/confirm', [TotpController::class, 'confirm']);
        Route::delete('mfa/totp', DisableTotpController::class);
        Route::post('mfa/recovery-codes/regenerate', [RecoveryCodeController::class, 'regenerate']);

        Route::get('sessions', [SessionController::class, 'index']);
        Route::delete('sessions/{sessionId}', [SessionController::class, 'destroy']);
        Route::delete('sessions', [SessionController::class, 'destroyAll']);
    });
});

/*
 * `docs/handoff/031-pwa-backend-endpoints.md` — which `TaxSubject`s the authenticated user can
 * access (driven by `subject_memberships`, `docs/handoff/011-identity-tenancy.md`), and
 * registering a new PF one (`App\Domain\Subjects\Actions\RegisterPfSubject`, reused verbatim).
 * Neither route binds `{subject}`/`tax-subject` — listing must work *before* any subject is
 * chosen, and registration creates one.
 */
Route::prefix('v1/subjects')->middleware('auth:web')->group(function (): void {
    Route::get('/', ListSubjectsController::class);
    Route::post('pf', RegisterPfSubjectController::class);
});

/*
 * `docs/handoff/022-document-pipeline.md` — Milestone 7. `tax-subject` binds
 * `App\Domain\Subjects\Tenancy\TenantContext` from `{subject}` (docs/handoff/011-identity-
 * tenancy.md step 2) before any controller runs; every business query below is tenant-scoped
 * automatically from that point on.
 */
Route::prefix('v1/subjects/{subject}')->middleware(['auth:web', 'tax-subject'])->group(function (): void {
    Route::post('documents', UploadDocumentController::class)
        ->middleware('throttle:documents-upload-per-subject');
    // The subject's own documents, from the server rather than from whichever device uploaded them.
    Route::get('documents', ListDocumentsController::class);
    Route::get('documents/{document}', ShowDocumentController::class);
    // Removes the row, its derivatives and the encrypted bytes. A transaction built from it keeps
    // its own row with a null link — the figure was real; only its evidence is gone.
    // Naming a document something recognisable; until then it is shown under its content hash.
    Route::patch('documents/{document}', RenameDocumentController::class);
    Route::delete('documents/{document}', DeleteDocumentController::class);
    Route::get('documents/{document}/content', ShowDocumentContentController::class);
    // What was done to this document: the virus-scan records, and this document's own entries in
    // the subject's audit chain. Subject-scoped, unlike the cross-tenant /v1/audit screens.
    Route::get('documents/{document}/history', ShowDocumentHistoryController::class);
    // The bridge: a reviewed document becomes a wallet transaction that keeps it as evidence. A
    // person does this, so the figures are the ones they saw and agreed to.
    Route::post('documents/{document}/transaction', CreateTransactionFromDocumentController::class);

    Route::get('documents/{document}/extractions', ListDocumentExtractionsController::class);
    // The remedy offered next to a recorded extraction failure on the review screen.
    Route::post('documents/{document}/extractions/retry', RetryDocumentExtractionController::class);
    Route::post('documents/{document}/extractions/{extraction}/accept', AcceptDocumentExtractionController::class);
    Route::post('documents/{document}/extractions/{extraction}/correct', CorrectDocumentExtractionController::class);

    Route::post('document-ai-consent', [DocumentAiConsentController::class, 'store']);
    Route::delete('document-ai-consent', [DocumentAiConsentController::class, 'destroy']);

    /*
     * Wallet (`BLUEPRINT.md` §6.4) — accounts, deposits and their totals for the active tax
     * subject.
     *
     * The writes are thin over `App\Domain\Wallet\Actions\*`, which own the encryption of the
     * display name and IBAN and the rate-coverage rule on a deposit. Nothing is reimplemented in the
     * HTTP layer, so a record created from the screen is the same shape as one a future import
     * produces.
     *
     * Inside the `{subject}` group deliberately: every Wallet model is `ScopedToTaxSubject`, so
     * these rows only exist relative to one subject and the `tax-subject` middleware binds it
     * before any query runs.
     */
    Route::get('wallet/overview', ShowWalletOverviewController::class);
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
    Route::post('wallet/accounts/{account}/balance', RecordBalanceController::class)->where('account', '[0-9a-f-]{36}');


    Route::get('wallet/transactions', ShowTransactionsController::class);
    Route::post('wallet/transactions', RecordTransactionController::class);
    Route::patch('wallet/transactions/{transaction}', UpdateTransactionController::class)->where('transaction', '[0-9a-f-]{36}');
    Route::delete('wallet/transactions/{transaction}', DeleteTransactionController::class)->where('transaction', '[0-9a-f-]{36}');
});

/*
 * `docs/handoff/041-settings-module.md` — system-wide runtime settings (today: the AI
 * document-extraction provider). Deliberately outside the `v1/subjects/{subject}` group above:
 * these settings have no tax subject at all — `tax-subject` binds a `TenantContext` from a
 * `{subject}` route parameter that does not apply here. Gated by a platform permission, not any
 * `subject_memberships` role, since changing where taxpayer documents are sent is a system-wide
 * administrative act, not a per-subject one (`docs/adr/0013-system-settings-scope-and-admin-
 * gate.md`).
 */
Route::prefix('v1/settings')->middleware(['auth:web'])->group(function (): void {
    // Reading system configuration and changing it are separate permissions: an auditor or a
    // support engineer often needs the first and must not have the second. `permission:` is
    // Spatie's own middleware — there is no environment allowlist any more (docs/adr/0014).
    Route::middleware('permission:system.settings.view')->group(function (): void {
        // Every group, with each setting's effective value and whether it comes from the database
        // or is inherited from configuration. Read-only keys are refused by the update route
        // itself, not merely disabled in the UI.
        Route::get('/', ShowSettingsController::class);
        Route::get('ai', ShowAiSettingsController::class);
        Route::get('health', ShowSystemHealthController::class);
        Route::get('ai/providers', ShowAiProvidersController::class);
    });

    Route::middleware('permission:system.settings.manage')->group(function (): void {
        // The AI group keeps its own richer endpoints (secret handling, connection test).
        Route::put('ai', UpdateAiSettingsController::class);
        Route::delete('ai/api-key', ClearAiApiKeyController::class);
        // Both reach out to the configured provider with real credentials, so they are writes in
        // every sense that matters even though neither stores anything.
        Route::post('ai/test-connection', TestAiConnectionController::class);
        Route::post('ai/models', ListAiModelsController::class);

        // Namespaced under `group/` deliberately: a bare `{group}` wildcard collides with the
        // AI-specific routes above, and `PUT settings/ai` then reaches the generic controller —
        // or, worse, the AI controller reaches a partial payload and rejects it with "the enabled
        // field is required". Distinct paths, no ordering subtleties.
        Route::put('group/{group}', UpdateSettingsGroupController::class)
            ->where('group', '[a-z_]+');
    });
});

// The audit trail names every actor and resource across all tax subjects, so it sits behind the
// same administrator gate as system settings rather than ordinary `auth:web`.
Route::prefix('v1/audit')->middleware(['auth:web', 'permission:audit.view'])->group(function (): void {
    Route::get('events', ShowAuditEventsController::class);
    Route::get('summary', ShowAuditSummaryController::class);
});

/*
 * Legislation search (`/legislatie`) — hybrid lexical + semantic retrieval over ingested sources
 * (`BLUEPRINT.md` §15.2).
 *
 * `auth:web` and nothing more: this is public law, not tenant data, so there is no `{subject}` to
 * bind and no per-subject permission to check. It is behind authentication at all because each call
 * runs an embedding model, and an unauthenticated endpoint that does that is a free way to burn the
 * server's CPU.
 */
Route::get('v1/legislation/search', SearchLegislationController::class)->middleware('auth:web');

/*
 * Background operations (`/admin/operatiuni`) — the scheduler, the queue, data freshness and the
 * official-source allowlist.
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
        Route::get('/', ShowOperationsOverviewController::class);
        // One feed rather than a `runs` listing beside a `failed-jobs` listing: two tables of
        // mostly red rows read as duplication, and a reader looking for "what went wrong in the
        // background" had to check both. The rows keep their differences — see the controller.
        Route::get('activity', ShowOperationsActivityController::class);
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
        // `config/sources.php`, and `App\Domain\TaxKnowledge\Repositories\
        // OfficialSourceRepository::resolve()` upserts `official_sources` from that config on every
        // fetch — so a value written here would survive only until the next import, while
        // `AllowlistedHttpFetcher` never consulted the row in the first place. The screen renders
        // the allowlist read-only rather than offering a switch that reports a state it cannot hold.
    });
});

// User administration. Same gate as settings and audit: this lists every account on the
// installation, with decrypted email addresses.
Route::prefix('v1/admin')->middleware(['auth:web'])->group(function (): void {
    Route::middleware('permission:users.view')->group(function (): void {
        Route::get('users', ShowUsersController::class);
        Route::get('access', ShowAccessCatalogueController::class);
    });

    Route::middleware('permission:users.manage')->group(function (): void {
        Route::put('users/{user}/roles', UpdateUserRolesController::class);
        Route::put('users/{user}/status', UpdateUserStatusController::class);
    });
});

// The signed-in user's own account. No permission beyond being authenticated: everything here is
// scoped to `$request->user()` and can only ever read or change that one account.
// Which tax subjects permit external AI processing. A set rather than a value per subject, so a
// consent screen needs one request instead of one per row.
Route::get('v1/documents/ai-consents', ShowDocumentAiConsentsController::class)->middleware('auth:web');

Route::prefix('v1/me')->middleware('auth:web')->group(function (): void {
    Route::get('/', ShowProfileController::class);
    Route::put('password', UpdatePasswordController::class);
});
