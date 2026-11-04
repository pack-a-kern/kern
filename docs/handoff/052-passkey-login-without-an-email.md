# 052 — Passkey login without an email

## What was built

"Sign in with passkey" on the login page works with nothing typed, as it does on other sites: the
browser offers the passkeys it keeps for this site, and the one chosen says whose account it is.
Email and password are unchanged. The email-first passkey flow (handoff 017) is gone, not kept beside.

## Files changed

- `PasskeyManager` — `requestOptions(string $ceremonyKey)` and `verifyAssertion(...)` replace
  `requestOptionsForEmail()`/`verifyAssertionForEmail()`. Registration asks for `residentKey: required`
  (the library adds the legacy `requireResidentKey: true`).
- `PasskeyLoginController` — no `email` on either route; the ceremony key is `sha256(session id)`.
- `CompletePasskeyLogin` — takes the ceremony key instead of an email.
- `ChallengeStore` — docblock: login challenges are keyed by the asking browser's session.
- Frontend: `authApi.ts`, `pages/auth/login.tsx`; `auth.enterEmailForPasskey` removed from i18n.
- Tests: `PasskeyTest.php` (login tests rewritten, five new), `SecurityLogTest.php`.

## Key decisions

- **The account comes from the passkey, never from the client.** Found by credential id, owned by the
  account that registered it. The device's `userHandle` must be that account — checked here, and again
  by the library against the stored credential record.
- **The challenge is bound to the session that asked.** A challenge, or a signed answer to one, taken
  to another browser is refused. One pending challenge per session, so asking again replaces it and the
  cache cannot be filled.
- **`residentKey: required`.** A key the device does not keep could never be offered at a login that
  names no account; refusing it at registration says so where it can be said.
- **Every refusal is the same generic error**, and the options are the same for everyone — the request
  carries no account, so there is nothing to enumerate.

## How to verify

```bash
docker compose exec -T app sh -c 'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/pest tests/Feature/Platform/Identity/PasskeyTest.php'
```

Checked by breaking each protection: with the ceremony key the same for every session, "refuses a
challenge that another browser was given" fails; with this code's `userHandle` check removed, the
library's own check still refuses the foreign handle.

## Threat model

| Threat | Answer |
|---|---|
| Brute force, flooding | `passkey-ceremony-per-ip` (20/min) on both routes; one challenge per session |
| Replay | challenge pulled once (`ChallengeStore::pull*`), 120 s TTL; tested |
| Challenge moved to another browser | keyed by that session; tested |
| Passkey presented as another account | `userHandle` must be the credential's owner, twice; tested |
| Cloned authenticator | signature counter, by the library, as before |
| Suspended account | refused at `EstablishesAuthenticatedSession`, as before; tested |
| Account enumeration | no account in the request; identical options; generic errors |
| Audit | `AuthLoginSucceeded` on success, `LOGIN_FAILED` (method only) on refusal, as before |

## What is NOT done

- Passkeys offered from the email field's autofill (`mediation: "conditional"`).
- A passkey registered before this change with an authenticator that kept no discoverable key cannot be
  offered; it has to be added again.
