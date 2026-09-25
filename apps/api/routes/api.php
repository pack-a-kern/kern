<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ShowUsersController;
use App\Http\Controllers\Api\V1\Documents\CreateTransactionFromDocumentController;
use App\Http\Controllers\Api\V1\Settings\ShowPlatformDataController;
use Illuminate\Support\Facades\Route;
use Kern\Documents\Http\Controllers\ShowDocumentAiConsentsController;

/*
|--------------------------------------------------------------------------
| The application's own API
|--------------------------------------------------------------------------
|
| Almost nothing. Every endpoint this product serves is shipped by the package that owns it —
| `kern/platform` for identity and settings, `kern/audit`, `kern/documents`, `kern/legislation`,
| `kern/tax-romania` — each loading its own routes at `config('kern.routing')`.
|
| What is left here is what belongs to no single package: the four endpoints that compose two
| packages' facts, which is the one thing an application is for. Wrapped in the `web` middleware
| group by `bootstrap/app.php`, not Laravel's stateless `api` group: authentication here is a
| session cookie.
*/

/*
 * A reviewed document becomes a wallet transaction that keeps it as evidence. A person does this, so
 * the figures are the ones they saw and agreed to. Not in `kern/documents`, because a transaction is
 * Wallet's, and not in `kern/tax-romania`, because a document is Documents' — a package cannot reach
 * into another, and this is exactly the seam between them.
 */
Route::prefix('v1/subjects/{subject}')->middleware(['auth:web', 'tax-subject'])->group(function (): void {
    Route::post('documents/{document}/transaction', CreateTransactionFromDocumentController::class);
});

// Which tax subjects permit external AI processing. A set rather than a value per subject, so a
// consent screen needs one request instead of one per row.
Route::get('v1/documents/ai-consents', ShowDocumentAiConsentsController::class)->middleware('auth:web');

/*
 * The two administrative endpoints that compose a package's facts with this product's.
 *
 * `kern/platform` ships the rest of `v1/admin` and `v1/settings` itself. These two do not belong to
 * it: one reports how many `subject_memberships` hold each tenant role, the other reports how fresh
 * the BNR rates and the legislation index are. Both are questions about a taxpayer's world, which
 * the platform deliberately knows nothing about. Same permissions as the routes they sit beside.
 */
Route::middleware(['auth:web', 'permission:users.view'])
    ->get('v1/admin/users', ShowUsersController::class);

Route::middleware(['auth:web', 'permission:system.settings.view'])
    ->get('v1/settings/platform-data', ShowPlatformDataController::class);
