# 053 — Seeing and removing passkeys; the account page on a phone

## What was built

- **Passkeys you can see and remove.** "Manage" on the Passkey row (shown once there is one) opens a
  dialog listing each passkey: its name (or "Passkey"), whether it is synced, when it was added and
  last used, and **Remove** — confirmed inline, then the password again (`verify-recent`).
- **Sign-in methods** read the same in every row on a phone: name and state, description, controls.
- **Sessions** are a striped list, one line per device (name, "this device", IP · last active) with
  **End** in the destructive style; **End other sessions** too.

## Files

- `packages/platform`: `Identity/Actions/RemovePasskey.php`, `Http/Controllers/Auth/PasskeyController.php`
  (new); `routes/identity.php` — `GET auth/passkeys`, `DELETE auth/passkeys/{passkeyId}` behind
  `verify-recent`; `lang/en/auth.php` `last_passkey`.
- Frontend: `pages/account/PasskeysDialog.tsx` (new), `SignInMethods.tsx`, `SessionsCard.tsx`;
  `components/panel` — `PanelButton` `variant="destructive"`, `Row` `status`; `api/authApi.ts`,
  `types/auth.ts`, i18n (the sessions table's `columns.device` / `columns.lastUsed`, now unread, removed).
- Tests: `tests/Feature/Platform/Identity/PasskeyManagementTest.php`.

## Key decisions

- **Only the account's own passkeys.** Another account's id answers 404, the same as one that never
  existed. The list carries no credential id or public key.
- **Removing needs the password again**, as adding does: a stolen session must not strip a factor.
- **The last way in stays.** An account with no password keeps its last passkey (422).
- **Audited** as `PasskeyRemoved`, beside `PasskeyRegistered`.
- **One rendering for sessions** at every width; the stripes are the tables' own 45% of `--muted`.

## Threat model

| Threat | Answer |
|---|---|
| Removing someone else's passkey | scoped to the signed-in account; 404 otherwise; tested |
| Stolen session strips a factor | `verify-recent` (password); tested |
| Locking yourself out | last passkey of a password-less account refused; tested |
| Learning credential material | the list returns name, synced, dates only; tested |
