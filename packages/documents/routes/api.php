<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Kern\Documents\Http\Controllers\AcceptDocumentExtractionController;
use Kern\Documents\Http\Controllers\CorrectDocumentExtractionController;
use Kern\Documents\Http\Controllers\DeleteDocumentController;
use Kern\Documents\Http\Controllers\DocumentAiConsentController;
use Kern\Documents\Http\Controllers\EnhanceImageController;
use Kern\Documents\Http\Controllers\ListDocumentExtractionsController;
use Kern\Documents\Http\Controllers\ListDocumentsController;
use Kern\Documents\Http\Controllers\RenameDocumentController;
use Kern\Documents\Http\Controllers\RetryDocumentExtractionController;
use Kern\Documents\Http\Controllers\ShowDocumentContentController;
use Kern\Documents\Http\Controllers\ShowDocumentDerivativeContentController;
use Kern\Documents\Http\Controllers\ShowDocumentController;
use Kern\Documents\Http\Controllers\ShowDocumentHistoryController;
use Kern\Documents\Http\Controllers\ShowDocumentPartContentController;
use Kern\Documents\Http\Controllers\UploadDocumentController;

/*
|--------------------------------------------------------------------------
| Documents — JSON routes
|--------------------------------------------------------------------------
|
| Loaded by `Kern\Documents\DocumentsServiceProvider` inside the tenant group
| `config('kern.routing.tenant')` names — here `v1/subjects/{subject}` with the middleware that
| binds a taxpayer. Nothing below spells that out, which is the point: a document belongs to a
| tenant, and what a tenant is called is the application's to say.
|
| `throttle:documents-upload-per-subject` is defined by this package's provider.
*/

Route::post('documents', UploadDocumentController::class)
    ->middleware('throttle:documents-upload-per-subject');
// The subject's own documents, from the server rather than from whichever device uploaded them.
// A photograph, cleaned up before it is uploaded. Stores nothing; the screen sends the result.
Route::post('documents/enhance', EnhanceImageController::class)
    ->middleware('throttle:documents-upload-per-subject');
Route::get('documents', ListDocumentsController::class);
Route::get('documents/{document}', ShowDocumentController::class);
// Removes the row, its derivatives and the encrypted bytes. A transaction built from it keeps
// its own row with a null link — the figure was real; only its evidence is gone.
// Naming a document something recognisable; until then it is shown under its content hash.
Route::patch('documents/{document}', RenameDocumentController::class);
Route::delete('documents/{document}', DeleteDocumentController::class);
Route::get('documents/{document}/content', ShowDocumentContentController::class);
// One page of a document made of several files; the review screen pages through these.
Route::get('documents/{document}/parts/{part}/content', ShowDocumentPartContentController::class);
// What the installation made from the document, as opposed to what it is made of — a statement's
// CSV conversion today. `{kind}` is resolved through the closed enum before it reaches the store.
Route::get(
    'documents/{document}/derivatives/{kind}/content',
    ShowDocumentDerivativeContentController::class,
);
// What was done to this document: the virus-scan records, and this document's own entries in
// the subject's audit chain. Subject-scoped, unlike the cross-tenant /v1/audit screens.
Route::get('documents/{document}/history', ShowDocumentHistoryController::class);
Route::get('documents/{document}/extractions', ListDocumentExtractionsController::class);
// The remedy offered next to a recorded extraction failure on the review screen.
Route::post('documents/{document}/extractions/retry', RetryDocumentExtractionController::class);
Route::post('documents/{document}/extractions/{extraction}/accept', AcceptDocumentExtractionController::class);
Route::post('documents/{document}/extractions/{extraction}/correct', CorrectDocumentExtractionController::class);

Route::post('document-ai-consent', [DocumentAiConsentController::class, 'store']);
Route::delete('document-ai-consent', [DocumentAiConsentController::class, 'destroy']);
