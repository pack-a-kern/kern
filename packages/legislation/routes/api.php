<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Legislation\Http\Controllers\CancelPortalSearchController;
use Kern\Legislation\Http\Controllers\DeleteLawController;
use Kern\Legislation\Http\Controllers\ImportPortalActsController;
use Kern\Legislation\Http\Controllers\ListLawsController;
use Kern\Legislation\Http\Controllers\SearchLegislationController;
use Kern\Legislation\Http\Controllers\Settings\ListUserAgentsController;
use Kern\Legislation\Http\Controllers\Settings\ReplaceBrowserIdentityController;
use Kern\Legislation\Http\Controllers\Setup\ShowRequirementsController;
use Kern\Legislation\Http\Controllers\ShowLawController;
use Kern\Legislation\Http\Controllers\ShowLawOutlineController;
use Kern\Legislation\Http\Controllers\ShowLawTextController;
use Kern\Legislation\Http\Controllers\ShowPortalSearchController;
use Kern\Legislation\Http\Controllers\ShowProvisionHistoryController;
use Kern\Legislation\Http\Controllers\StartPortalSearchController;
use Kern\Legislation\Http\Controllers\Watches\AllowIgnoredActController;
use Kern\Legislation\Http\Controllers\Watches\CreateLawWatchController;
use Kern\Legislation\Http\Controllers\Watches\DeleteLawWatchController;
use Kern\Legislation\Http\Controllers\Watches\ListLawWatchesController;
use Kern\Legislation\Http\Controllers\Watches\RunDeclaredLawWatchController;
use Kern\Legislation\Http\Controllers\Watches\RunLawWatchController;
use Kern\Legislation\Http\Controllers\Watches\UpdateLawWatchController;

/*
|--------------------------------------------------------------------------
| Legislation — JSON routes
|--------------------------------------------------------------------------
|
| Loaded by `Kern\Legislation\LegislationServiceProvider` at `config('kern.routing')`, outside any
| tenant group: this is public law, not anybody's data. There is no `{subject}` to bind and no
| per-tenant permission to check.
|
| `auth:web` at all because each search runs an embedding model, and an unauthenticated endpoint that
| does that is a free way to burn the server's CPU.
*/

/*
 * Legislation search (`/legislation`) — hybrid lexical + semantic retrieval over ingested sources
 * (`BLUEPRINT.md` §15.2).
 *
 * No `{subject}` and no permission: this is public law, not tenant data. It is behind authentication
 * and a per-account throttle because each call runs an embedding model, and an endpoint that does
 * that without either is a free way to burn the server's CPU.
 */
Route::get('v1/legislation/search', SearchLegislationController::class)
    ->middleware(['auth:web', 'throttle:legislation-search-per-user']);

// Finding an act on the portal to read it: a search that reaches out to legislatie.just.ro, so for the
// same people who may start reading one. Started and answered at once, then read a page a job (ADR 0024);
// read back and cancelled by whoever started it. Starting is throttled like any operator call; reading
// one back is a row of this database, asked again while it runs.
Route::prefix('v1/legislation/portal-searches')->middleware(['auth:web', 'permission:operations.run'])->group(function (): void {
    Route::post('/', StartPortalSearchController::class)->middleware('throttle:operator-calls-per-user');
    Route::get('{search}', ShowPortalSearchController::class)->whereUuid('search');
    Route::delete('{search}', CancelPortalSearchController::class)->whereUuid('search');
});

// The laws read, each with its consolidations, its text on a date and a provision's history — the
// same public law, behind the same authentication and for the same reason.
Route::prefix('v1/legislation/laws')->middleware('auth:web')->group(function (): void {
    Route::get('/', ListLawsController::class);
    Route::get('{law}', ShowLawController::class)->whereUuid('law');
    Route::get('{law}/text', ShowLawTextController::class)->whereUuid('law');
    Route::get('{law}/outline', ShowLawOutlineController::class)->whereUuid('law');
    Route::get('{law}/history', ShowProvisionHistoryController::class)->whereUuid('law');

    // Removing a law read in from the portal — one added to try, or the wrong act — is changing what
    // this installation holds, so it belongs to the same people who may start a reading. A law any
    // fiscal value rests on is refused rather than cascaded: see `DeleteLaw`.
    // Removes the text figures are traced to, past undoing: asked again for the password or code. The
    // deleting is a job that holds the single legislation worker for minutes, so it is rate-limited per
    // person as the other operator calls on this queue are, lest a flood of removals starve ingestion.
    Route::delete('{law}', DeleteLawController::class)->whereUuid('law')
        ->middleware(['permission:operations.run', 'verify-recent', 'throttle:operator-calls-per-user']);
});

// Several acts chosen from a portal search, read one after another: the same people, and the same limit on
// how often, as starting one reading through the ingest task.
Route::post('v1/legislation/imports', ImportPortalActsController::class)
    ->middleware(['auth:web', 'permission:operations.run', 'throttle:operator-calls-per-user']);

// Searches of the portal kept and run again on their own clock, each new act read as a law: the
// packages' (shown, paused at most) and those a person keeps. Reaching out to the portal is the same
// people's who may start a reading.
Route::prefix('v1/legislation/watches')->middleware(['auth:web', 'permission:operations.run', 'throttle:operator-calls-per-user'])->group(function (): void {
    Route::get('/', ListLawWatchesController::class);
    // Each change sets what the server fetches from the portal, on a clock, or stops it: asked again for the
    // password or code (`verify-recent`), so a session alone cannot.
    Route::post('/', CreateLawWatchController::class)->middleware('verify-recent');
    Route::patch('{watch}', UpdateLawWatchController::class)->whereUuid('watch')->middleware('verify-recent');
    Route::delete('{watch}', DeleteLawWatchController::class)->whereUuid('watch')->middleware('verify-recent');
    // Running one now reads what it finds as it would at its hour: nothing it would not do on its own.
    Route::post('{watch}/run', RunLawWatchController::class)->whereUuid('watch');
    // Running a package-declared watch by its key, mirroring its row first if a fresh install has not
    // yet (ADR 0027). The key is matched against the declarations in config, so it brings no watch of
    // the caller's choosing into being — like "Run now", it needs no `verify-recent`.
    Route::post('declared/{key}/run', RunDeclaredLawWatchController::class)->where('key', '[a-z0-9_.-]{1,64}');
    // An act taken off the ignored list is read in by the next run that finds it: changed as a watch is.
    Route::delete('ignored/{ignored}', AllowIgnoredActController::class)->whereUuid('ignored')->middleware('verify-recent');
});

// What every package declared it needs to operate, each need resolved, with the install's
// bootstrapped flag and each feature's availability (ADR 0027). Install state, not a tenant's data,
// so outside the tenant group like the rest of this file; the gate sends an authenticated person
// here, so `auth:web` and no more.
Route::get('v1/setup/requirements', ShowRequirementsController::class)->middleware('auth:web');

// Who this installation says it is, on the Browser card: the names it may pick from, by engine, and the
// engine and name saved together — asked again for the password or code, as every change of identity.
Route::prefix('v1/settings/browser')->middleware('auth:web')->group(function (): void {
    Route::get('user-agents', ListUserAgentsController::class)->middleware('permission:system.settings.view');
    Route::put('identity', ReplaceBrowserIdentityController::class)
        ->middleware(['permission:system.settings.manage', 'verify-recent', 'throttle:operator-calls-per-user']);
});
