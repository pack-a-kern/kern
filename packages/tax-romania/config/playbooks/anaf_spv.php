<?php

declare(strict_types=1);

/*
 * How to sign in to ANAF's SPV portal and read the inbox, as instructions for `apps/browser-worker`.
 *
 * ## What has been observed, and what has not
 *
 * **Observed 2026-10-06, with `tools/observe-spv-page.js`:** the login page. Its selectors below are
 * real, and two guesses it corrected are worth recording so nobody reinstates them:
 *
 * - the portal is `pfinternet.anaf.ro`, not `www.anaf.ro/InregPersFizicePublic/` — that path is where
 *   its *documentation* and credential recovery live, which is exactly the kind of plausible wrong
 *   answer a guess produces;
 * - the login is served by an F5 BIG-IP access policy (`/my.policy`, and ids `input_1`/`input_2`
 *   generated positionally). Those ids are not used below: they are an artefact of the appliance and
 *   would move if a field were ever added above them. `name` is what the form itself means.
 *
 * **Observed 2026-10-06:** the page that asks for the emailed code, which turned out to be the reason
 * not to guess at any of this. It is the same URL, the same form, the same submit button — and the
 * field holding the code is `input[name="password"]`, the *same name and id as the password field on
 * the login page*. A sequence that waited for a password field to decide the code had been asked for
 * would match the login page it was still standing on and report success at the wrong moment.
 *
 * What does distinguish them is that the code page has no username row. Read as an absence that is a
 * race, so it is anchored on a row that parses after the missing one — see `signIn`'s last step.
 *
 * It also confirms a number rather than asserting one: "Acest cod de siguranţă expiră după 300
 * secunde" is where `SPV_CHALLENGE_TTL_SECONDS`'s 300 comes from, and the code is sent from
 * `Autentificare.SpatiuPrivat@anaf.ro`.
 *
 * **Observed 2026-10-06:** a refused login, as HTML rather than through the observer — which is how
 * it was found that the observer saw nothing at all, because a refusal is prose and prose was the one
 * thing it did not collect. It does now.
 *
 * **Not observed:** the inbox. Its selectors are still guesses and are marked as such. `docs/AGENTS.md` §1 rule 1 forbids writing against a DOM that has not
 * been seen, and that holds in a config file exactly as in code — so they stay marked rather than
 * quietly becoming fact.
 *
 * Replacing a guess is this file and nothing else. No rebuild of the worker, no deploy of it, no PHP
 * to change — which is the whole reason the worker takes a playbook instead of having ANAF compiled
 * into it.
 *
 * ## A PHP file rather than YAML
 *
 * The same shape every other reference list in this package uses, for the same reasons: it caches
 * with `config:cache`, it takes comments, and it needs no parser. `symfony/yaml` is present only as
 * something another package pulled in, and depending on that is depending on a decision nobody made.
 */
return [
    'version' => 1,
    'name' => 'anaf-spv',

    /*
     * Where this playbook may send the browser, checked before every navigation and again on every
     * redirect. One entry, which admits its own subdomains and nothing that merely ends the same
     * way: `spv.anaf.ro` yes, `evil-anaf.ro` no.
     */
    'allow' => ['anaf.ro'],

    'context' => [
        // Romanian, because a portal that localises by locale should answer in the language whose
        // error messages the selectors below will eventually match.
        'locale' => 'ro-RO',
        // Nothing here reads a picture, and a page without them loads in a fraction of the time.
        'block' => ['image', 'font', 'media'],
    ],

    'sequences' => [
        /*
         * Step one of a sitting: the credentials, and whichever page they lead to.
         *
         * The two outcomes are the ones the screen already draws. `awaiting_challenge` means ANAF
         * has emailed a code and the browser is waiting on this side of it; `failed` means it said
         * no, and `message` carries back what it said so a person is told rather than guessed at.
         */
        'signIn' => [
            /*
             * The site root, not `/my.policy` directly.
             *
             * `/my.policy` is where the form is served and submitted, but an F5 access policy hands
             * out a session at the entry point and expects it back — arriving mid-policy is the kind
             * of thing that works until it does not. The redirect is followed, and checked against
             * `allow` again when it is: `pfinternet.anaf.ro` is admitted by `anaf.ro` as a subdomain,
             * which is why that entry needs no widening.
             */
            ['goto' => 'https://pfinternet.anaf.ro/'],
            // Observed. By `name` rather than the `input_1`/`input_2` ids beside them — see the header.
            ['fill' => ['selector' => 'input[name="username"]', 'value' => '{{ secret.username }}']],
            ['fill' => [
                'selector' => 'input[name="password"]',
                'value' => '{{ secret.password }}',
                'sensitive' => true,
            ]],
            /*
             * The form's own submit — caption "Autentificare", and the only one on the page. Clicking
             * it rather than submitting the form by script is what carries the hidden `vhost` field
             * the appliance also expects; nothing here has to know that field exists.
             */
            ['click' => ['selector' => 'input[type="submit"]']],
            /*
             * The code page is the credentials table with no username field in it.
             *
             * Structural, not textual — ANAF is free to reword a Romanian sentence and a selector
             * quoting one would break on a copy edit. The end states differ plainly: the code page drops
             * the username row, a refused login keeps both fields.
             *
             * `:has(#submit_row)` is what makes reading it as an absence safe. A document parses in
             * order, so a table that already contains its submit row would contain the username row too
             * if that row were coming; without the anchor, this matches a half-parsed table mid-
             * navigation and announces the code page while landing on a refusal. Verified against all
             * three shapes — refused login 0, code page 1, half-parsed table 0 — rather than reasoned
             * about and hoped for.
             *
             * If ANAF ever renames the table or the submit row, this stops matching and the step times
             * out. A failure, not a wrong answer, which is the right way round.
             *
             * A refusal is a red message in the login table's post-header cell, observed as:
             *
             *     <td id="credentials_table_postheader"><font color="red">
             *       Utilizatorul sau parola nu sunt corecte. Va rugam să incercati din nou.
             *     </font></td>
             *
             * Matched on the red `font` rather than on those words, so that a different refusal —
             * locked account, too many attempts — is still recognised instead of timing out as though
             * nothing had happened. The cell is read for `message`, so the person is told what ANAF
             * said rather than that something went wrong.
             *
             * Race-free, and that is the point of choosing this over the obvious alternatives: the
             * cell exists on the clean login page (six rows there and six here) but holds no red
             * element, so nothing here can match the page the browser has not left yet. "The username
             * field is still present" would have matched it instantly, every time.
             *
             * These ids belong to the F5 BIG-IP login template the portal is served by, not to ANAF —
             * `credentials_table`, `credentials_input_text` and the rest are the appliance's. That
             * makes them outlive an ANAF redesign and die with an appliance change, which is the
             * opposite lifetime from what it looks like.
             */
            ['waitFor' => [
                'any' => [
                    [
                        'selector' => '#credentials_table:has(#submit_row):not(:has(input[name="username"]))',
                        'outcome' => 'awaiting_challenge',
                    ],
                    [
                        'selector' => '#credentials_table_postheader font[color="red"]',
                        'outcome' => 'failed',
                        'message' => '#credentials_table_postheader',
                    ],
                ],
            ]],
        ],

        /*
         * Step two, minutes later: the code from the email, into the browser still open from step
         * one. The session is what holds that browser; this sequence assumes nothing about how long
         * it took somebody to find the message.
         */
        'challenge' => [
            /*
             * Observed. `input[name="password"]` really is where the emailed code goes — the same name
             * the password went into one page earlier, on a form ANAF reuses for both steps. Reading
             * that as a copy-paste slip and "fixing" it to something like `#otp` is the one change that
             * would break this sequence while looking like a correction.
             *
             * `sensitive` for the same reason the password is: a one-time code is still a credential
             * while it is live, and ANAF's own page says it stays live for 300 seconds.
             */
            ['fill' => [
                'selector' => 'input[name="password"]',
                'value' => '{{ secret.code }}',
                'sensitive' => true,
            ]],
            // Observed: the same single submit, captioned "Autentificare", as on the login page.
            ['click' => ['selector' => 'input[type="submit"]']],
            /*
             * The refusal branch is the login form's, which this step can reuse because it is the same
             * form: a rejected code prints into the same red cell. UNOBSERVED for the success branch —
             * nobody has seen the inbox a good code leads to, so `table.mesaje` is still a guess.
             */
            ['waitFor' => [
                'any' => [
                    ['selector' => 'table.mesaje', 'outcome' => 'authenticated'],
                    [
                        'selector' => '#credentials_table_postheader font[color="red"]',
                        'outcome' => 'failed',
                        'message' => '#credentials_table_postheader',
                    ],
                ],
            ]],
        ],

        /*
         * The inbox, as rows rather than HTML.
         *
         * The field names are this application's, not the portal's: `SpvInboxMessage` is what the
         * import expects, and a playbook that renamed them would move the mapping somewhere nobody
         * looks for it.
         */
        'inbox' => [
            // UNOBSERVED. The field *names* are real — they are ANAF's own, from the documented
            // certificate API that `AnafCertificateApiTransport` already reads. Which cell holds which
            // is the guess.
            ['extract' => [
                'rows' => 'table.mesaje tbody tr',
                'fields' => [
                    'id' => ['attr' => 'data-id'],
                    'detalii' => ['text' => 'td.detalii'],
                    'cif' => ['text' => 'td.cif'],
                    'data_creare' => ['text' => 'td.data'],
                ],
                'into' => 'messages',
            ]],
        ],
    ],
];
