<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Investments\Http\Controllers\ListInstrumentPayoutsController;
use Kern\Investments\Http\Controllers\ListInstrumentPricesController;
use Kern\Investments\Http\Controllers\ListInstrumentsController;
use Kern\Investments\Http\Controllers\ListPayoutsController;
use Kern\Investments\Http\Controllers\RefreshIssuerProfileController;
use Kern\Investments\Http\Controllers\ShowInstrumentController;
use Kern\Investments\Http\Controllers\ShowIssuerLogoController;
use Kern\Investments\Http\Controllers\ShowIssuerProfileController;

/*
|--------------------------------------------------------------------------
| Investments — JSON routes (ADR 0025 §7)
|--------------------------------------------------------------------------
|
| Public market data, the same for everyone, so outside any tenant group — and behind authentication
| and a per-person budget all the same: an unauthenticated list of the market is a free way to scrape
| this server. Read-only, but for the one call that reaches out to BVB, which has a budget of its own.
*/

Route::prefix('v1/investments')->middleware(['auth:web', 'throttle:investments-reads-per-user'])->group(function (): void {
    Route::get('instruments', ListInstrumentsController::class);
    Route::get('instruments/{instrument}', ShowInstrumentController::class)->whereUuid('instrument');
    Route::get('instruments/{instrument}/prices', ListInstrumentPricesController::class)->whereUuid('instrument');
    Route::get('instruments/{instrument}/payouts', ListInstrumentPayoutsController::class)->whereUuid('instrument');
    Route::get('payouts/{when}', ListPayoutsController::class)->whereIn('when', ['upcoming', 'recent']);
    Route::get('issuers/{key}', ShowIssuerProfileController::class)->where('key', '[A-Z0-9]{1,16}');
    Route::post('issuers/{key}/refresh', RefreshIssuerProfileController::class)
        ->where('key', '[A-Z0-9]{1,16}')
        ->middleware('throttle:investments-refreshes-per-user');
});

/*
 * The issuer logo is a cached static image, served from local disk and cached hard by the browser.
 * It sits outside the market reads budget on a light throttle of its own, so a market page showing a
 * logo per row does not spend a person's read allowance on images.
 */
Route::prefix('v1/investments')->middleware(['auth:web', 'throttle:240,1'])->group(function (): void {
    Route::get('issuers/{key}/logo', ShowIssuerLogoController::class)->where('key', '[A-Z0-9]{1,16}')->name('investments.issuer-logo');
});
