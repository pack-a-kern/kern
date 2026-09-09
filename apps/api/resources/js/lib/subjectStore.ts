/**
 * The "active tax subject" the PWA operates against.
 *
 * `BLUEPRINT.md` §5.2/§6.1 models a `User` as owning/accessing one or more `TaxSubject`s.
 * `GET /api/v1/subjects` (`resources/js/api/subjectsApi.ts`, `docs/handoff/031-pwa-backend-
 * endpoints.md`) is the real, authoritative list; this module only remembers *which one of that
 * list* the user picked last, so `/dashboard`, `/documents` and `/documents/capture` don't ask
 * again on every visit. A `tax_subject_id` is an opaque internal identifier, not one of
 * `BLUEPRINT.md` §4.3's mandatory-encryption fields (CNP, IBAN, address, ...) — storing it in
 * `localStorage` is the same category of "which workspace am I in" convenience most multi-tenant
 * SPAs keep client-side, not "sensitive fiscal data" (`BLUEPRINT.md` §1.3/§17). Every reader of this
 * value must still treat it as a hint, not a fact: `resources/js/pages/settings/subjects.tsx`
 * re-validates it against the real list on load and clears it if the membership behind it is gone
 * (revoked, or a stale value from a previous account on a shared device). Wrapped in try/catch
 * because `localStorage` can throw in a locked-down/private-browsing context; losing this
 * convenience must never crash the app.
 */
const STORAGE_KEY = 'taxos.activeSubjectId';

export function getActiveSubjectId(): string | null {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

export function setActiveSubjectId(subjectId: string): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, subjectId);
    } catch {
        // Best-effort convenience only — see module docblock.
    }
}

export function clearActiveSubjectId(): void {
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Best-effort convenience only — see module docblock.
    }
}
