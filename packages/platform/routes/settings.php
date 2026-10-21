<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Platform\Http\Controllers\Settings\ListAiModelsController;
use Kern\Platform\Http\Controllers\Settings\ReplaceAiConnectionController;
use Kern\Platform\Http\Controllers\Settings\ShowAiProvidersController;
use Kern\Platform\Http\Controllers\Settings\ShowSettingsController;
use Kern\Platform\Http\Controllers\Settings\ShowSystemHealthController;
use Kern\Platform\Http\Controllers\Settings\TestAiConnectionController;
use Kern\Platform\Http\Controllers\Settings\UpdateSettingsGroupController;

/*
|--------------------------------------------------------------------------
| Settings — JSON routes
|--------------------------------------------------------------------------
|
| Loaded by `Kern\Platform\Settings\SettingsServiceProvider`, under the `api` prefix and the `web`
| middleware group the application supplies. They travel with the package because they are the
| package: a screen that lists settings is the application's to design, but *what* the API is is not.
*/

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
        Route::get('health', ShowSystemHealthController::class);
        // `GET settings/platform-data` is not here: what this installation may fetch from, and how
        // fresh what it fetched is, is a question about *this product's* data. The application
        // registers it in `routes/api.php`.
        Route::get('ai/providers', ShowAiProvidersController::class);
    });

    Route::middleware('permission:system.settings.manage')->group(function (): void {
        // The provider's address and key, together — the only route that writes either. Everything
        // else in the AI group is saved through `group/ai` below, which refuses those two.
        Route::put('ai/connection', ReplaceAiConnectionController::class)->middleware('throttle:operator-calls-per-user');
        // Both reach out to the configured provider with real credentials, so they are writes in
        // every sense that matters even though neither stores anything.
        Route::post('ai/test-connection', TestAiConnectionController::class)->middleware('throttle:operator-calls-per-user');
        Route::post('ai/models', ListAiModelsController::class)->middleware('throttle:operator-calls-per-user');

        // Namespaced under `group/` deliberately: a bare `{group}` wildcard collides with the
        // AI-specific routes above, and `PUT settings/ai` then reaches the generic controller —
        // or, worse, the AI controller reaches a partial payload and rejects it with "the enabled
        // field is required". Distinct paths, no ordering subtleties.
        Route::put('group/{group}', UpdateSettingsGroupController::class)
            ->where('group', '[a-z_]+');
    });
});
