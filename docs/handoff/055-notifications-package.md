# 055 — The notifications package, and one class per kind of notice

**Milestone:** — (outside the milestones)   **Status:** complete
**Date:** 2026-11-05

## What was built

`kern/notifications` (`packages/notifications`): the bell, notices on people's devices (Web Push), and a
switch per kind — installable in any Laravel application, requiring nothing of this repository. Its
README is the user guide, with a full example kind (`OrderShipped`), the endpoints, the browser side,
security and configuration. ADR 0022 records why.

A kind of notice is now one class (`Contracts\NoticeType`: `key`, `label`, `hint`, `render`). The server
writes every notice's words when it is shown — the bell and the phone say the same — and the frontend
displays `{title, body, url, level}` with no list of kinds. This product's two kinds are in
`packages/tax-romania/src/Notices/`.

Adding a kind: write the class and its texts, register it in the package's provider
(`NoticeTypes::register()`), send it with `$user->notify(TheKind::about(...))`. Nothing else.

## Files created / changed

- `packages/notifications/` — new package. `src/Notice.php` (the sendable), `Contracts/NoticeType.php`,
  `Services/{NoticeTypes,Preferences,PushSender}.php`, `Channels/{BellChannel,PushChannel}.php`,
  `Models/{Device,Preference,StoredNotice}.php`, `Http/Controllers/*`, `Rules/PushServiceAddress.php`,
  `Events/*`, `Actions/ForgetNotifiable.php`, `Testing/{FakePushService,BrowserSubscription}.php`,
  `Console/GeneratePushKeysCommand.php` (`notifications:keys`), config, lang (en, ro), migrations (the
  `notifications` table moved here from the platform, same file name), `.env.example`, own suite.
- `packages/platform` — the old `src/Notifications`, its controllers, config, lang, command,
  `UserNotification` and the `User::notifications()` override removed. New `src/Identity/Devices/`:
  `EncryptedDevice` (sealed under the user's DEK), `DevicesNamedLikeSessions`, `RecordDeviceChanges`
  (audit). `EraseUser` calls `ForgetNotifiable`. `Schema::morphUsingUuids()` in the provider.
- `packages/tax-romania` — `src/Notices/{TaxpayerNotice,StatementImportNotice,DepositMaturityNotice}.php`
  replace the two old notice classes; texts in `lang/{en,ro}/notifications.php` (the bell's wording).
- `packages/documents`, `packages/legislation` — list `kern/notifications`, as they list `kern/audit`.
- Frontend — `types/notifications.ts` (generic notice), `AppShell` bell (no per-kind code),
  `lib/echo.ts` (the socket gives an id), `api/{notificationsApi,pushApi}.ts` (`?locale=`),
  `types/push.ts`, `NotificationsCard`, `lib/subjectStore.ts` (`?subject=` links),
  `SubjectSwitcher`, `app.tsx`; per-kind bell strings removed from `i18n`; `getLocale()` removed.
- `apps/api` — `composer.json`/`lock` (adds `kern/notifications`, drops the direct web-push
  requirement; no third-party version moved), `phpstan.neon`, tests: `DeviceNoticesTest`,
  `DepositMaturityRemindersTest`, the bell part of `StatementImportTest`.
- `Makefile` (package lists), `scripts/install.sh` (command name), ADR 0022, handoff 054 (pointer),
  `docs/STATUS.md`, `docs/reference/verified-versions.md`, `packages/tax-romania/README.md`.

## Key decisions

See ADR 0022. In short:

- **The bell is unread notices only**, no history for now; `unread` counts what is shown, so a notice
  left out does not light the bell.
- **Rendered in batches**: each kind gets all its notices at once (`render($notices, …)`). Bell cost is
  constant — tested (`DepositMaturityRemindersTest`: the same queries for one deposit as for three).
- **Access asked as a list** (`tenantsFor`), because `roleFor` writes a security event per refusal.
- **Words on read, not on write.** `data` holds ids and codes; `render()` writes the text per request,
  so the deposit's amount and bank appear in the bell and on the phone without being stored. The
  broadcast carries id and kind only; the page fetches the words over HTTPS.
- **Access is checked again on every render.** `TaxpayerNotice` asks `TenantGate`; a revoked
  membership hides the taxpayer's notices from the bell and stops them reaching devices.
- **`BellChannel`, not Laravel's `database` channel**: writes to the microsecond for any model (the
  product's old `UserNotification` fix, now in the package). A preset id was tried and dropped: Laravel
  gives every recipient of one send the same preset id.
- **One key per kind**: `statement-import` (the key already stored) and `deposit-maturity`.
- **`?subject=` on links**, adopted when a page loads; the switcher drops it when someone switches, so a
  reload does not switch them back. Inertia rewrites the address on first load, so it is not stripped.
- **Failed imports**: the bell no longer names the failure reason (that would mean the reason texts in
  two languages twice, PHP and TypeScript); it says the import stopped and opens it, where the reason is.

## How to verify

```
make check-packages
── kern/audit          Tests:    12 passed (19 assertions)
── kern/notifications  Tests:    31 passed (115 assertions)
── kern/platform       Tests:    106 passed (202 assertions)
── kern/documents      Tests:    3 warnings, 95 passed (311 assertions)   # ClamAV-unreachable tests, unrelated
── kern/legislation    Tests:    82 passed (216 assertions)
── kern/tax-romania    Tests:    154 passed (390 assertions)

make check-php-pg      Tests:    12 skipped, 1499 passed (5286 assertions)
sqlite                 Tests:    120 skipped, 1391 passed (4828 assertions)
pint --test            PASS 272 files · PASS 1359 files (packages)
phpstan                [OK] No errors
deptrac                Violations 0 · Errors 0
composer audit         No security vulnerability advisories found.
tsc --noEmit           ok
eslint                 0 errors (7 warnings, all in files this did not touch)
prettier --check       All matched files use Prettier code style!
vitest                 289 passed
vite build             built
```

On a phone: Contul meu → Notificări → Activează → Trimite un test. Then a deposit maturing in 7 days and
`php artisan kern:wallet:remind-maturities`: the bell and the phone show the same text with the amount.

## Interfaces exposed to other modules

`Kern\Notifications\Contracts\NoticeType`, `Notice`, `Services\NoticeTypes::register()`,
`DataTransferObjects\Message`, `Enums\Level`, `Notifications::useDeviceModel()`,
`Contracts\DescribesDevices`, `Events\DeviceAdded`/`DeviceRemoved`, `Actions\ForgetNotifiable`,
`Testing\FakePushService`, `Testing\BrowserSubscription`. Endpoints under `api/v1/notifications` (README).

## Threat model

| Threat | Answer |
|---|---|
| A device makes the server call an address (SSRF) | push-service host allow-list, https, port 443, no userinfo; on store and on send; tested |
| Another person's devices or notices | every query scoped to the signed-in person; others' devices are 404; tested |
| A copy of the table used to send | address and keys sealed under the user's DEK, bound to the row; tested |
| An amount or bank at rest or on the socket | `data` holds ids; the broadcast holds id and kind; tested |
| A notice keeps showing what access was revoked to | `render()` asks `TenantGate` each time; tested |
| A notice links off-site | `Message` refuses absolute, `//`, `\`; bell and service worker open same-origin only; tested |
| The push service reads the text | RFC 8291 encryption by `minishlink/web-push`; tested on the wire |
| Flooding | per-person throttles on device changes and test sends; device cap; tested |
| Erased account still notified | `EraseUser` → `ForgetNotifiable`; tested |

## What is NOT done

- Not checked in a browser or on a phone after this change (the API, the bell's data and the card's data
  are covered by tests; the screens were not looked at).
- Not committed.

## Open questions

None.

## Sources consulted

RFC 8291, RFC 8292; `minishlink/web-push` 11.0.0; Laravel 13 `NotificationSender` (the shared preset
id), Inertia 3.7.0 `history.replaceState` on first load.
