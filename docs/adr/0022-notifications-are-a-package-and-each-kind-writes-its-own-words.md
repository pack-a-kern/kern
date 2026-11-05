# ADR 0022 — Notifications are their own package, and each kind of notice writes its own words

**Status:** accepted
**Date:** 2026-11-05
**Extends:** [ADR 0016](0016-installable-packages-with-a-security-only-base.md) — a sixth package.

## Context

The bell and device notifications (handoff 054) worked, but a kind of notice was written in four
places: the notice class (what is stored, and the device's text), a declaration in the package's
provider (the switch), and, in the frontend, a parser per kind and a function writing the bell's text
from the stored ids. Adding a kind meant changing all four, and the bell and the phone said different
things about the same notice — the bell's words were TypeScript, the phone's were PHP.

The code also lived in `kern/platform` and leaned on it throughout — its `User`, its per-user keys, its
audit chain, its rate-limit helper — so no other application could use it, which is the point of
splitting the product into packages.

## Decision

**`kern/notifications`**, a package that requires Laravel and `minishlink/web-push` and nothing of this
repository. It holds the bell's table and endpoints, the devices, the switches, sending, and the key
command.

**A kind of notice is one class** (`Contracts\NoticeType`): its key, its switch's label and hint, and
`render($notices, $notifiable, $locale)` — what each of its notices says, given all of them at once so
each lookup is one query. Notices are sent as one generic
`Notice(type, data)`. The server writes the words each time a notice is shown, for the bell and the
device alike; the table holds only `data` (ids and codes), and the broadcast carries only the id and
kind, so `BLUEPRINT.md` §16 holds without each kind having to remember it. The frontend displays
`{title, body, url, level}` and has no list of kinds.

**`render()` is also where access is checked again.** A notice is shown only while its reader may still
see what it is about; in this product, while they still act for the taxpayer (`TenantGate`). A revoked
membership ends what the bell shows, as it ends everything else.

**Where the package needs the application, it asks** — never imports:

| Need | Seam | This product |
|---|---|---|
| encrypt device keys its own way | `Notifications::useDeviceModel()` | `EncryptedDevice`, sealed under the user's DEK |
| name devices | `Contracts\DescribesDevices` | the sessions list's `DeviceSummary` |
| audit device changes | `Events\DeviceAdded`, `DeviceRemoved` | `RecordDeviceChanges` → audit chain |
| erase a person | `Actions\ForgetNotifiable` | called by `EraseUser` |
| polymorphic key type | Laravel's `Schema::morphUsingUuids()` | set by the platform |

**A link names its taxpayer** (`?subject=`), read once when a page loads, because a notice can be about
another taxpayer than the one open — on a phone, where there is no bell to switch it from.

The product's kinds live together in `kern/tax-romania/src/Notices/`, over a `TaxpayerNotice` base that
does the access check and the link.

## Consequences

- Adding a kind is one class and its texts; nothing in the frontend.
- The bell's words come from the server. The frontend's translated import and deposit strings are gone;
  so is the failure reason in the bell's text for a failed import, which the import page shows.
- **The bell lists unread notices only.** Rendering on read costs a fixed handful of queries per kind,
  whatever the number of notices (here: memberships once, imports once, deposits and banks once each);
  once everything is read the bell writes nothing. There is no history of read notices for now.
- Access is asked as one list (`TenantGate::tenantsFor`), not a refusal per notice: `roleFor` records
  each refusal as a security event, and a bell is not an access attempt.
- One key per kind for both the stored notice and the switch: `statement-import`, the key stored
  notices already had, so rows written before this change still show.
- `push_subscriptions` became `notification_devices`, with a polymorphic owner and no foreign key;
  erasure goes through `ForgetNotifiable`. Neither table had been released.
