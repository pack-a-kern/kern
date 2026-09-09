/**
 * Client-side feature flags. There is currently no authorization mechanism for "is this user a
 * platform administrator" — Spatie's roles/permissions package is being adopted in a separate,
 * concurrent task (per `docs/STATUS.md`) but does not exist in this codebase yet. Gating the
 * admin section of the navigation behind a fake role check would be worse than an honest flag:
 * it would look like real authorization to the next person who reads this file, when the actual
 * enforcement (or lack of it) lives entirely server-side regardless of what this constant says —
 * every `/admin/**` page is still a client-only placeholder with no privileged data or action
 * behind it (`docs/handoff/040-ui-foundation.md` "Admin navigation").
 *
 * @todo(TAXOS-ROLES) Replace this constant with a real check — e.g. `usePage().props.auth.user.can('platform.admin')`
 * fed by Spatie's `HasRoles`/`HasPermissions` once that package lands — and delete this file.
 * Until then this defaults to `true` only so every item in the navigation is reachable for this
 * UI-foundation review; it must not ship as `true` once real users with real roles exist.
 */
export const ADMIN_UI_ENABLED: boolean = true;
