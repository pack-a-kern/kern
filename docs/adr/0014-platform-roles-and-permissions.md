# ADR 0014 — Platform roles and permissions (supersedes the allowlist in ADR 0013)

**Status:** accepted · supersedes the `SETTINGS_ADMIN_USER_IDS` decision in ADR 0013

## Context

ADR 0013 gated system settings with a deploy-time allowlist of user ids in `SETTINGS_ADMIN_USER_IDS`,
on the grounds that `MembershipRole` is scoped to a `TaxSubject` and a system-wide setting has none.
That held while one gate existed. It stopped holding once the audit log and user administration
arrived: three route groups, needing different levels of access, all collapsed onto one boolean.

It also had properties that only look acceptable while nobody is using the system. Access was
granted by editing an environment variable and restarting a container, so it could not be granted
by an administrator, could not be audited, and was invisible in every table — the only way to
answer "who can change the AI provider?" was to read a `.env` on a server.

## Decision

`spatie/laravel-permission` (pinned `8.3.0`), with the catalogue declared in `config/access.php`
and projected into the database by `taxos:access:sync`.

- **Permissions are code-defined.** A permission only means something when a route or policy checks
  it; one created at runtime is a label that protects nothing. Declaring them in a reviewed file
  keeps "what can be protected" and "what is protected" in the same change.
- **Roles hold permissions; users hold roles.** Four roles today: `SUPER_ADMIN`, `ADMIN`,
  `ACCOUNTANT`, `VIEWER`.
- **Reading and writing are separate permissions.** `system.settings.view` vs
  `system.settings.manage`, `users.view` vs `users.manage`. An auditor needs the first and must not
  have the second.
- **Unenforced permissions are marked as such.** `config/access.php` lists the permissions nothing
  checks yet, and the matrix renders them outlined rather than ticked. A matrix that ticks a
  permission no code consults asserts a protection that does not exist.
- **`SETTINGS_ADMIN_USER_IDS` is removed entirely**, along with `SettingsAdministrators` and the
  `settings-admin` middleware. Routes use Spatie's `permission:` middleware directly.
- **Bootstrapping is a shell command**, `taxos:access:grant {email} {role}`. The first administrator
  cannot be created through a UI that requires `users.manage`; requiring shell access is the same
  trust boundary as reaching the database.

## Consequences

- Granting access is now a recorded, auditable act (`ROLE_CHANGED` in the audit chain) rather than a
  configuration change nobody sees.
- Lockout is possible in a way the allowlist made impossible, so it is guarded explicitly: you
  cannot remove your own last role, cannot demote the last `SUPER_ADMIN`, and cannot suspend
  yourself. Recovery from a total loss is `taxos:access:grant` on the host.
- `MembershipRole` is untouched and still enforced by nothing. Platform roles answer "what may you
  do to the installation"; per-subject roles will answer "what may you do to this taxpayer's data",
  and that is a separate piece of work.
- Adding a permission requires a code change. That is the intended cost: see the first bullet.

## Notes for whoever implements per-subject permissions

`ShowUsersController` returns `enforcement.membership_roles_enforced: false`, and
`AdminUserDirectoryTest` asserts it. When those checks land, that test fails — which is the point:
whoever implements them has to update the flag and the screen's copy in the same change.
