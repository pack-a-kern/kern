# SPV-PF portal — observed structure

**Observed:** 2026-09-08, by rendering the **public, pre-login page** with the project's own
Playwright worker. **No credentials were entered and no input values were read** — the capture
takes element names, roles and labels only.

## The portal for individuals

`https://formularespv-pf.anaf.ro/` — titled *"Depunere declarație unică și alte formulare SPV-PF"*,
reached from ANAF's own forms index. This is the D212 submission entry point for natural persons.

## Login page — observed elements

| Element | Observed |
|---|---|
| Username field | `input[name="username"]`, `type=text`, `id="input_1"`, **no `<label>`** |
| Password field | `input[name="password"]`, `type=password`, `id="input_2"`, **no `<label>`** |
| Submit | `input[type=submit]`, accessible name **"Autentificare"** |
| Hidden field | `input[name="vhost"]` |
| Form target | `POST /my.policy` |

**`/my.policy` identifies this as an F5 BIG-IP Access Policy Manager front end**, not a bespoke
ANAF login. Useful context: such gateways commonly enforce their own session cookies and can
present interstitial pages (session expiry, policy redirects) that automation must expect.

### Consequence for selector policy
`BLUEPRINT.md` §13.5 prefers role/label selectors. Here that is only partly possible: **the two input
fields have no `<label>` and no `aria-label`**, so `getByLabel()` will not find them. The honest
choices are `input[name="username"]` / `input[name="password"]` (stable, semantic, server-defined)
and `getByRole('button', { name: 'Autentificare' })` for submit. Do **not** use `#input_1`/`#input_2`
— generated ids are the fragile option §13.5 warns against.

## Still unobserved — do not invent
Everything **after** authentication: the message inbox, the D212 form flow, the submission
confirmation, any OTP/second-factor step, and every error state. `docs/AGENTS.md` rules 1 and 2
still apply to all of it.

## Related finding — the D212 artifact is a "PDF inteligent"
The same page states that the taxpayer applications are **„pdf-urile inteligente"** (smart PDFs),
and that since 2023-01-08 they run only on vendor-supported, fully updated operating systems.

This tells us the D212 artifact is an **XFA/LiveCycle smart PDF with embedded form logic**, not a
plain XML upload — which is the first hard information the project has about `STATUS.md` blocked
item 2. It is a strong lead, **not** a specification: the actual field names, the embedded schema
and the accepted submission envelope are still unknown and must not be guessed.
