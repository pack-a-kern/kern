<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Legislation\Http\Controllers\CancelPortalSearchController;
use Kern\Legislation\Http\Controllers\DeleteLawController;
use Kern\Legislation\Http\Controllers\ListLawsController;
use Kern\Legislation\Http\Controllers\SearchLegislationController;
use Kern\Legislation\Http\Controllers\ShowLawController;
use Kern\Legislation\Http\Controllers\ShowLawOutlineController;
use Kern\Legislation\Http\Controllers\ShowLawTextController;
use Kern\Legislation\Http\Controllers\ShowPortalSearchController;
use Kern\Legislation\Http\Controllers\ShowProvisionHistoryController;
use Kern\Legislation\Http\Controllers\StartPortalSearchController;

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
    Route::delete('{law}', DeleteLawController::class)->whereUuid('law')->middleware('permission:operations.run');
});
