import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { cspNonce, shareCspNonce } from './lib/cspNonce';
import { registerServiceWorker } from './lib/registerServiceWorker';
import { adoptSubjectFromLink } from './lib/subjectStore';
import { appNameFrom } from './lib/sharedProps';
import { stopFieldZoomOnIos } from './lib/viewport';
import AppShell from './components/AppShell';
import StepUpGate from './components/StepUpGate';
import { Toaster } from './components/ui/sonner';
import { TooltipProvider } from './components/ui/tooltip';

/*
 * Filled in from the first page Inertia hands us, below, because the name belongs to the server —
 * `VITE_APP_NAME` meant the tab title was decided when the assets were built, so renaming the
 * application left the old name in every tab until someone rebuilt the frontend.
 *
 * Assigned in `setup()`, which runs before anything renders, and therefore before any `<Head>`
 * asks for a title. The first load's title comes from Blade and never waits on this.
 */
let appName = '';

/**
 * The screens that stand on their own rather than in the application's frame: those before sign-in,
 * and the setup screen — a signed-in person is held there until the install can compute (ADR 0027),
 * so it wears its own full-page layout, not the shell whose every destination the gate has closed.
 */
const PAGES_WITHOUT_SHELL = new Set(['home', 'auth/login', 'auth/verify-email', 'setup/index']);

// Before anything renders, so the first dialog's `<style>` already carries the page nonce.
shareCspNonce();
registerServiceWorker();
stopFieldZoomOnIos();
// Before any screen asks which taxpayer it is in.
adoptSubjectFromLink();

const nonce = cspNonce();

void createInertiaApp({
    // On the `<style>` Inertia inserts for its progress bar and its error dialog — see lib/cspNonce.ts.
    // Left out, not passed as undefined, when the page carries none.
    ...(nonce === undefined ? {} : { nonce }),
    title: (title) => (title ? `${title} — ${appName}` : appName),
    // The application's frame around every page but the ones before sign-in — kept mounted between
    // pages, so a click swaps the screen and nothing around it (see AppShell).
    layout: (name) => (PAGES_WITHOUT_SHELL.has(name) ? undefined : AppShell),
    resolve: (name) => {
        const pages = import.meta.glob<{ default: React.ComponentType }>('./pages/**/*.tsx', { eager: true });
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Unknown Inertia page component: ${name}`);
        }

        return page;
    },
    setup({ el, App, props }) {
        appName = appNameFrom(props.initialPage.props);

        createRoot(el).render(
            <TooltipProvider>
                {/* Wraps every page, because any screen may call a route that demands a code and none
                    of them knows in advance which. The dialog it renders is closed until one does. */}
                <StepUpGate>
                    <App {...props} />
                </StepUpGate>
                <Toaster />
            </TooltipProvider>,
        );
    },
});
