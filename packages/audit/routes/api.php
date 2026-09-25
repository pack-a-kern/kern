<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Audit\Http\Controllers\Audit\ShowAuditEventsController;
use Kern\Audit\Http\Controllers\Audit\ShowAuditSummaryController;

/*
|--------------------------------------------------------------------------
| Audit — JSON routes
|--------------------------------------------------------------------------
|
| Loaded by `Kern\Audit\AuditServiceProvider` under the prefix and middleware
| `config('kern.routing')` names.
*/

// The audit trail names every actor and resource across all tax subjects, so it sits behind the
// same administrator gate as system settings rather than ordinary `auth:web`.
Route::prefix('v1/audit')->middleware(['auth:web', 'permission:audit.view'])->group(function (): void {
    Route::get('events', ShowAuditEventsController::class);
    Route::get('summary', ShowAuditSummaryController::class);
});
