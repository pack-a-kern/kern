<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Milestone 7 PWA frontend (`docs/handoff/030-pwa-frontend.md`). Every page here renders an
 * Inertia component that fetches its own data from `/api/v1/**` client-side — these routes carry
 * no props beyond a raw route parameter, and no domain model is imported (`docs/AGENTS.md` §6:
 * this module has no business logic of its own to protect by binding one).
 *
 * `/dashboard` and everything under `/documents` are behind Laravel's default `auth` middleware
 * alias (guard `web`, `config/auth.php` — `docs/handoff/017-authentication.md`), which is
 * registered by the framework itself and needs no alias of its own in `bootstrap/app.php`. This
 * route file names the `login` route so `Illuminate\Auth\Middleware\Authenticate`'s default
 * `redirectTo()` has somewhere real to send an unauthenticated visit.
 */
Route::get('/', fn () => Inertia::render('home'))->name('home');

Route::get('/login', fn () => Inertia::render('auth/login'))->name('login');

Route::middleware('auth')->group(function (): void {
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
     * renders either a real screen (Security/Subjects/Privacy settings, reusing the exact
     * components `/dashboard` already used) or `pages/**` components that render the shared
     * `PlaceholderPage` — no controller, no domain model, same "no business logic here" shape the
     * six routes above already have.
     */
    Route::get('/portofel', fn () => Inertia::render('wallet/overview'))->name('wallet.overview');
    Route::get('/portofel/conturi', fn () => Inertia::render('wallet/accounts'))->name('wallet.accounts');
    Route::get('/portofel/tranzactii', fn () => Inertia::render('wallet/transactions'))->name('wallet.transactions');

    Route::get('/chirii', fn () => Inertia::render('rentals'))->name('rentals');

    Route::get('/impozite', fn () => Inertia::render('taxes/overview'))->name('taxes.overview');
    Route::get('/impozite/explica', fn () => Inertia::render('taxes/explain'))->name('taxes.explain');

    Route::get('/declaratii', fn () => Inertia::render('declarations'))->name('declarations');
    Route::get('/legislatie', fn () => Inertia::render('legislation'))->name('legislation');
    Route::get('/spv', fn () => Inertia::render('spv'))->name('spv');

    Route::get('/setari/profil', fn () => Inertia::render('settings/profile'))->name('settings.profile');
    Route::get('/setari/securitate', fn () => Inertia::render('settings/security'))->name('settings.security');
    Route::get('/setari/subiecte', fn () => Inertia::render('settings/subjects'))->name('settings.subjects');
    Route::get('/setari/confidentialitate', fn () => Inertia::render('settings/privacy'))->name('settings.privacy');

    /*
     * Admin routes carry no server-side authorization yet — there is no role system to enforce
     * with (`resources/js/lib/featureFlags.ts`'s `@todo(TAXOS-ROLES)`). They are reachable by any
     * authenticated user today, same as every other route in this file, and render nothing but the
     * same client-only `PlaceholderPage`. Gating these for real is explicitly out of this task's
     * scope — see the handoff's "Admin navigation" section.
     */
    Route::get('/admin/setari', fn () => Inertia::render('admin/settings'))->name('admin.settings');
    Route::get('/admin/parametri', fn () => Inertia::render('admin/parameters'))->name('admin.parameters');
    Route::get('/admin/utilizatori', fn () => Inertia::render('admin/users'))->name('admin.users');
    Route::get('/admin/operatiuni', fn () => Inertia::render('admin/operations'))->name('admin.operations');
    Route::get('/admin/audit', fn () => Inertia::render('admin/audit'))->name('admin.audit');
});
