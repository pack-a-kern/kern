# 054 — Notifications on people's devices (Web Push)

> **Superseded in its structure by [055](055-notifications-package.md):** the code below moved into
> `kern/notifications`, and a kind of notice is now one class. What it does for a person is unchanged.

## What was built

The bell's notices can also reach a person's phone or computer, as the device's own notifications.
On the account page, a **Notificări** card turns them on for this device, lists the devices that have
them, sends a test, and has one switch per kind of notice. The kinds are declared by the packages; the
card draws whatever the server lists. Two kinds today: statement imports (the bell's existing notice)
and deposit maturity (new: a daily task, 09:00 in the schedule's timezone, 30/7/1/0 days before).

iOS and iPadOS give web notifications only to an app on the Home Screen (16.4+); the card says so on a
Safari tab. Android and desktop browsers work in a tab or installed.

## New dependency

`minishlink/web-push` v11.0.0 (MIT, released 2026-07-23, maintained). Web Push needs the message
encrypted to the device (RFC 8291, ECDH + AES-GCM) and each send signed (VAPID, RFC 8292); the policy is
that cryptography is never written here. It brought `php-http/discovery`, `php-http/httplug`,
`php-http/promise`, `spomky-labs/base64url`, `web-token/jwt-library`; it sends through the Guzzle already
installed. Added with `composer update minishlink/web-push` only — no other locked package moved.

## Files

- `packages/platform`
  - `src/Notifications/` — `NotificationTypeRegistry` (kinds packages declare), `NotificationPreferences`,
    `PushSender`, `Channels/WebPushChannel`, `Contracts/SendsPush`, `DataTransferObjects/{NotificationType,
    PushMessage}`, `Models/{PushSubscription,NotificationPreference}`, `Rules/PushServiceAddress`,
    `Notifications/TestPushNotification`, `NotificationsServiceProvider`.
  - `src/Http/Controllers/Notifications/{PushDevice,NotificationPreference,SendTestPush}Controller.php`;
    routes under `v1/notifications` in `routes/identity.php`.
  - `src/Console/Commands/Notifications/GeneratePushKeysCommand.php` (`kern:push:keys`).
  - `config/notifications.php`; migration `2026_11_05_100000_create_push_notification_tables`;
    `lang/{en,ro}/notifications.php`; `EraseUser` removes devices and choices.
- `packages/tax-romania`
  - `StatementImportNotice` implements `SendsPush`.
  - `Wallet/Notifications/DepositMaturityNotice`, `Wallet/Actions/FindDueMaturityReminders`,
    `Console/Commands/Wallet/RemindMaturingDepositsCommand`; `OperationTask::WALLET_MATURITY_REMINDERS`;
    migration `…create_deposit_maturity_reminders_table`; `wallet.deposit.reminder_days`;
    `lang/{en,ro}/notifications.php`; kinds declared in `TaxRomaniaServiceProvider`.
- Frontend: `public/sw.js` (push, notificationclick), `pages/account/NotificationsCard.tsx`,
  `lib/push.ts`, `api/pushApi.ts`, `types/push.ts`; the bell reads the deposit notice
  (`types/notifications.ts`, `AppShell`); `DeviceIcon` and `lib/devices.ts` shared with sessions.
- `scripts/install.sh` writes a key pair; `packages/platform/.env.example` documents `WEB_PUSH_*`.

## Key decisions

- **A device's address is checked before it is stored and again before each send**: `https`, default
  port, no credentials, on a known push service host (`notifications.push.hosts`). Otherwise a subscription
  would let anyone make the server call any address, its own network included.
- **Address and keys encrypted** with the account's own key (as the TOTP secret); `endpoint_hash` finds a
  device again.
- **The bell keeps ids only; the device gets the exact text.** The `notifications` table is not
  encrypted, so a deposit notice there has no amount or bank; the push text — bank, principal, date, in
  the device's language — is built at send time, kept nowhere, and encrypted to the device.
- **Switches choose what reaches devices only**; the bell keeps everything.
- **Once per deposit and distance** (`deposit_maturity_reminders`, unique pair). "Today" still covers a
  deposit the interest task closed at 00:15 that day; one closed early gets nothing.
- **Wallet does not read Banks** (Deptrac): the command in Console joins deposit, bank and owners.
- **A gone device (404/410) is deleted**; other refusals are logged by status code only.

## How to verify

```bash
docker compose exec -T app sh -c 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/pest tests/Feature/Platform/Notifications tests/Feature/TaxRomania/Wallet/DepositMaturityRemindersTest.php'
make check-php-pg
```

On a phone: Account → Notificări → Activează → Trimite un test.

## Threat model

| Threat | Answer |
|---|---|
| Server made to call an address (SSRF) | host allow-list, https, port 443, no userinfo, checked on store and send; tested |
| Another account's devices | listed and removed only by owner; 404 otherwise; tested |
| Database copy used to send | address and keys encrypted per account; tested |
| Amount readable at rest | bell row has ids only; push text built on send; tested |
| Push service reads the text | RFC 8291 encryption; the request body carries no plaintext; tested |
| Flooding | `push-devices-per-user`, `push-test-per-user`; per-account device cap; tested |
| Erased account still notified | devices and choices deleted with it; tested |

## Not done

- Notices for a sign-in from a new device and for SPV messages: none exists in the bell yet. Each is a
  new `SendsPush` notice and one `NotificationType` declaration.
