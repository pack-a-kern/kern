<?php

declare(strict_types=1);

use App\Http\Controllers\RecordCspViolationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Kern\Legislation\Http\Requests\ImportPortalActsRequest;
use Kern\Platform\Identity\Services\RegistrationPolicy;

/*
 * Milestone 7 PWA frontend (`docs/handoff/030-pwa-frontend.md`). Every page here renders an
 * Inertia component that fetches its own data from `/api/v1/**` client-side — these routes carry
 * no props beyond a raw route parameter, and no domain model is imported (`docs/AGENTS.md` §6:
 * this module has no business logic of its own to protect by binding one).
 *
 * Every path is English, whatever language the screen is shown in: a URL is an identifier, and one
 * that changed with the interface language could not be bookmarked or linked to.
 *
 * `/dashboard` and everything under `/documents` are behind Laravel's default `auth` middleware
 * alias (guard `web`, `config/auth.php` — `docs/handoff/017-authentication.md`), which is
 * registered by the framework itself and needs no alias of its own in `bootstrap/app.php`. This
 * route file names the `login` route so `Illuminate\Auth\Middleware\Authenticate`'s default
 * `redirectTo()` has somewhere real to send an unauthenticated visit.
 */
Route::get('/', fn () => Inertia::render('home'))->name('home');

// The Content-Security-Policy's report address. Unauthenticated because a browser sends these on its
// own, from the login page as much as any other; throttled because anyone can.
Route::post('/csp-report', RecordCspViolationController::class)
    ->middleware('throttle:60,1')
    ->name('csp-report');

/*
 * `registrationOpen` is passed here rather than shared globally: only this screen asks the
 * question, and a shared prop would put a `settings` query on every Inertia response in the
 * application — including the ones rendered before any of it is needed.
 */
// `guest` (Illuminate\Auth\Middleware\RedirectIfAuthenticated) sends an already-signed-in visitor
// to the dashboard rather than showing the sign-in form — its default target is the `dashboard`
// route when one exists, which it does. Registration is a mode of this same page, so this guards it
// too; a guest still reaches both.
Route::get('/login', fn (RegistrationPolicy $registration) => Inertia::render('auth/login', [
    'registrationOpen' => $registration->isOpen(),
]))->middleware('guest')->name('login');

/*
 * The verification screen is on `RequireVerifiedEmail`'s allowed list, which is the whole point: it is
 * where that middleware sends people, so being held there by it would be a loop. It draws itself from
 * `GET /api/v1/auth/email/verification` rather than from a prop, so opening it costs nothing and
 * refreshing it does not send a second code.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('/verify-email', fn () => Inertia::render('auth/verify-email'))->name('verify-email');
});

Route::middleware('auth')->group(function (): void {
    /*
     * The install's readiness checklist (ADR 0027 step 6). `RequireBootstrapped` sends an
     * authenticated person here until the legislation its required features rest on is in force,
     * so this route must stay reachable from inside that gate — it is on the gate's allowed set.
     * The screen draws itself from `GET /api/v1/setup/requirements`; no prop, no domain model.
     */
    Route::get('/setup', fn () => Inertia::render('setup/index'))->name('setup');

    Route::get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');

    Route::get('/documents', fn () => Inertia::render('documents/inbox'))->name('documents.inbox');
    Route::get('/documents/{document}/review', fn (string $document) => Inertia::render('documents/review', [
        'documentId' => $document,
    ]))->name('documents.review');

    /*
     * Full-navigation UI-foundation pass (`docs/handoff/040-ui-foundation.md` "Routes"). Every item
     * in `resources/js/components/AppShell.tsx`'s sidebar needs a real route so the product owner
     * can click through the whole planned product, not just the six screens with a working backend
     * — a link with nowhere to go is worse than an honestly-labelled placeholder. Every route below
     * renders either a real screen (the account and taxpayer screens, which
     * carry real behaviour) or `pages/**` components that render the shared
     * `PlaceholderPage` — no controller, no domain model, same "no business logic here" shape the
     * six routes above already have.
     */
    // Wallet has no page of its own: its summary is the dashboard, and in the menu it only opens these.
    Route::get('/wallet/accounts', fn () => Inertia::render('wallet/accounts'))->name('wallet.accounts');
    Route::get('/wallet/counterparties', fn () => Inertia::render('wallet/counterparties'))
        ->name('wallet.counterparties');
    // `countries` is a page prop rather than a shared one, for the same reason `counties` is:
    // only the classification dialog offers the list, and sharing it would put it on every Inertia
    // response in the application.
    Route::get('/wallet/transactions', fn () => Inertia::render('wallet/transactions', [
        'countries' => config('reference.countries.all'),
    ]))->name('wallet.transactions');

    /*
     * Importing a bank statement. Two screens rather than a dialog: reviewing a statement is several
     * hundred rows, which needs a page, and the wizard has to be returnable-to — a person who closes
     * the tab halfway comes back through `/wallet/imports`.
     */
    Route::get('/wallet/imports', fn () => Inertia::render('wallet/imports'))->name('wallet.imports');
    Route::get('/wallet/imports/new', fn () => Inertia::render('wallet/import', [
        'countries' => config('reference.countries.all'),
    ]))->name('wallet.imports.new');

    Route::get('/wallet/reports', fn () => Inertia::render('wallet/reports'))->name('wallet.reports');
    // Bonds and shares held, and the market they are chosen from (ADR 0025 §11). Public market data: the
    // pages ask the API, which needs a sign-in. Holdings were under the wallet once.
    Route::redirect('/wallet/holdings', '/investments/holdings');
    Route::get('/investments', fn () => Inertia::render('investments/market'))->name('investments.market');
    Route::get('/investments/holdings', fn () => Inertia::render('investments/holdings'))->name('investments.holdings');
    Route::get('/investments/{instrument}', fn (string $instrument) => Inertia::render('investments/instrument', [
        'instrumentId' => $instrument,
    ]))->whereUuid('instrument')->name('investments.instrument');

    Route::get('/rentals', fn () => Inertia::render('rentals'))->name('rentals');

    Route::get('/taxes', fn () => Inertia::render('taxes/overview'))->name('taxes.overview');
    // The values every tax is computed from; the page asks the API, which checks the permission.
    Route::get('/taxes/rules', fn () => Inertia::render('taxes/rules'))->name('taxes.rules');

    // The act types a law can be read as and the years one is looked for in, for "Add a law".
    Route::get('/legislation', fn () => Inertia::render('legislation', [
        'documentTypes' => config()->array('operations.legislation.document_types'),
        'years' => ['min' => config()->integer('legislation.form.min_year'), 'max' => (int) date('Y')],
        // The most acts one "Import selected" takes.
        'importMax' => ImportPortalActsRequest::maxActs(),
        // Whether the laws are searched by meaning as well as by their words, which the screen says.
        'byMeaning' => config()->boolean('ai.embeddings.enabled'),
    ]))->name('legislation');
    Route::get('/legislation/{law}', fn (string $law) => Inertia::render('legislation/law', [
        'lawId' => $law,
    ]))->whereUuid('law')->name('legislation.law');
    Route::get('/spv', fn () => Inertia::render('spv'))->name('spv');

    /*
     * Two screens where there were four. Profile, security, taxpayers and privacy were four routes
     * for what a person thinks of as two things: "my account" and "the taxpayers I act for". The
     * old paths are gone rather than redirected — nothing outside this file linked to them, and a
     * redirect kept for nobody is a route to maintain forever.
     */
    Route::get('/account', fn () => Inertia::render('account/index'))->name('account');
    // `counties` is a page prop rather than a shared one: only this screen's two dialogs offer the
    // list, and sharing it would put 42 rows on every Inertia response in the application.
    Route::get('/taxpayers', fn () => Inertia::render('subjects/index', [
        'counties' => config('reference.counties.ro'),
    ]))->name('subjects');

    // The same permission as the data each page asks for: someone who may not read it is refused the
    // page itself, rather than shown an empty one.
    Route::get('/admin/settings', fn () => Inertia::render('admin/settings'))
        ->middleware('permission:system.settings.view')->name('admin.settings');
    Route::get('/admin/users', fn () => Inertia::render('admin/users'))
        ->middleware('permission:users.view')->name('admin.users');

    /*
     * The audit trail, at the root rather than under `/admin`.
     *
     * "What happened" is a question asked far more often than anything on the administration
     * screens, and it is answered by reading rather than by changing configuration. The queue and
     * the task ledger shared this screen until both turned out to answer it by acting — retrying a
     * job, running a task — which is administration whatever the question that led there.
     *
     * Each screen still checks its own permission server-side; the navigation hides a row it knows
     * is refused, which is a courtesy and not the gate.
     */
    Route::get('/audit', fn () => Inertia::render('audit'))->name('audit');
    Route::get('/admin/jobs', fn () => Inertia::render('admin/jobs'))->name('admin.jobs');
    Route::get('/admin/tasks', fn () => Inertia::render('admin/tasks'))->name('admin.tasks');
});
