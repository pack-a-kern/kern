# ADR 0028 — Reading an identity card into a new taxpayer

**Status:** accepted, 2026-11-17

## Context

Adding a taxpayer (`RegisterPfSubject`) means typing a CNP, a name and an address off the person's
identity card (*carte de identitate*, CI). The card already carries all of it, and a vision model
can read it. We want: upload the card, have the model return the fields as JSON plus the location of
the face photo, crop that photo into the taxpayer's avatar, and pre-fill the form — turning a page
of typing into a review.

An identity card is the most sensitive single document this product handles: a CNP is a national
identifier, and the card also carries a face, an address and a document series/number. Several of
the project's standing rules bear on it at once — ADR 0005 (external AI is consent-gated and its
output is a proposal, never trusted), ADR 0021 (the AI provider is whatever the operator configured
on the settings screen), BLUEPRINT §4.10 (no sensitive value ever reaches a log or a transport
exception's text) and §14.2 (declared MIME is ignored; the bytes decide).

Two facts shape the design:

1. **The taxpayer does not exist yet.** The per-subject consent in `DocumentAiConsentGate`
   (ADR 0005) is keyed by a subject id, and there is no subject at read time. That gate cannot be
   the consent for this step.
2. **The card must never come to rest in plaintext anywhere we control, and never in a log.** A
   queued job that carried the image in its payload would leave the card sitting in Redis (the
   queue) in the clear until a worker picked it up — exactly the copy we must not keep.

## Decision

1. **The card is read by the configured vision model as a direct provider call**, the way
   `StatementAiClient` and `EnhanceImageController` already call it (via `AiDialect`,
   `ResolveAiProviderCredentials`, `AiProviderCatalogue`) — **not** through the stubbed
   `ExternalDocumentAiProvider`/`Document` path, because no `Document` is created: nothing about the
   card is stored.

2. **Consent is an explicit, per-request acknowledgement by the acting user**, not a per-subject
   `DocumentAiConsent`. The read request must carry `consent: true`; the controller records one
   audit event attributed to the acting user, naming the provider, before anything is sent. There
   is no subject to attach a stored consent to, and the person adding the taxpayer is the one
   deciding to send the card. (Once the taxpayer exists, ordinary subject consent governs later
   document sends — never this initial read.)

3. **The card never comes to rest in plaintext and never in a log.** The upload is held only in an
   **encrypted, single-use, short-TTL scratch store** (`IdCardScratch`: Laravel `Crypt` ciphertext
   in the cache, keyed by an unguessable token, bound to the acting user, deleted on first read).
   The queued `ReadIdCardJob` carries **only the token** — never the image. The worker pulls-and-
   deletes the image, reads it, writes the (encrypted) result under the same token, and the browser
   fetches that result once (also pull-and-delete). The PHP upload temp file is unlinked the moment
   its bytes are read. No log line, audit payload or broadcast ever carries the image, the CNP, or
   any field — audit payloads are hashes/sizes only, as avatars already are. Before any of that the
   bytes are sniffed (magic-byte type, JPEG/PNG only) **and run through the same `UploadScanner`
   (ClamAV) every kept upload gets**, fail-closed, before the card is sealed into scratch or reaches
   the provider — so this upload keeps the application's rule that bytes are virus-scanned before they
   go anywhere, rather than becoming a second exception to it alongside `EnhanceImageController`.

4. **Delivery is asynchronous over the socket, never a held request or a poll.** Upload returns
   `202 { token }` and dispatches the job; when the job finishes it broadcasts a **nudge only** —
   `{ token, status }`, no fields — on the acting user's own private channel (`users.{userId}`,
   already authorised). The browser then fetches the result over authenticated HTTPS. This is the
   ADR 0024 portal-search shape (the socket nudges, the screen fetches), chosen here specifically so
   a CNP never travels in a broadcast payload.

5. **The face is cropped in the browser, from the model's bounding box.** The model returns
   `face_box` as normalised `{x, y, w, h}` in `[0, 1]`. The browser — which still holds the card it
   uploaded — crops its own copy in a `<canvas>` and shows an adjustable crop the user can nudge.
   **The card is therefore never sent to the Python processing service**, which this feature does
   not touch at all: one fewer backend service ever sees the card. The resulting avatar is posted to
   the existing `POST /v1/subjects/{subject}/avatar` after the taxpayer is created, where `SetAvatar`
   re-scans and re-encodes it as for any avatar.

6. **The model's output is a proposal, never applied.** The fields pre-fill the existing, editable
   add-taxpayer form; the user confirms or corrects every one before `RegisterPfSubject` runs, and
   the CNP is revalidated by the existing rules. The bounding box is clamped to the image before
   cropping. This is ADR 0005's "trusted nowhere" discipline: the card accelerates the form, it
   does not fill the database.

7. **The scan is optional.** The add-taxpayer form works exactly as before with no card, with a
   declined acknowledgement, or with a read that fails or times out — typing remains the path, and
   the CNP input stays the source of truth.

## Consequences

- Sending the card to the provider is as safe as the provider the operator configured (ADR 0021).
  A cloud provider receiving a CNP is a data-protection decision the operator makes by configuring
  it and the user makes by acknowledging it; a self-hosted vision model keeps the card on the
  operator's own infrastructure and is the recommended posture. The acknowledgement names the
  provider for exactly this reason.
- The card touches, at most: the browser, PHP request memory, the encrypted scratch (ciphertext,
  seconds), and the configured provider. It is never written to our object store, never to a
  `Document`, never to a log, never to the Python service.
- The one unavoidable plaintext touch is PHP's own multipart temp file during upload; it is unlinked
  immediately after reading and the upload temp dir should be a tmpfs. Recorded as a deployment note
  rather than solved in code, since PHP writes that file before any of our code runs.
- A read needs a vision-capable model. A model that returns something other than the requested JSON
  surfaces as a clean "could not read the card — enter it by hand", not an error.
