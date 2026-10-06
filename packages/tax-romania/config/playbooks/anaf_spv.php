<?php

declare(strict_types=1);

/*
 * How to sign in to ANAF's SPV portal and read the inbox, as instructions for `apps/browser-worker`.
 *
 * ## The selectors here are placeholders
 *
 * Nobody has observed the real pages. `docs/AGENTS.md` §1 rule 1 forbids writing against a DOM that
 * has not been seen, and that applies to a selector in a config file exactly as it applies to one in
 * code — a guess is a guess wherever it is written. What is real here is the *shape*: three
 * sequences, the states a login can reach, and which field each step fills.
 *
 * Replacing them is this file and nothing else. No rebuild of the worker, no deploy of it, no PHP
 * to change — which is the whole reason the worker takes a playbook instead of having ANAF compiled
 * into it. Run `tools/observe-spv-page.js` in the browser's console on each page, and the names it
 * prints go straight into the selectors below.
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
            ['goto' => 'https://www.anaf.ro/InregPersFizicePublic/'],
            ['fill' => ['selector' => '#username', 'value' => '{{ secret.username }}']],
            ['fill' => ['selector' => '#password', 'value' => '{{ secret.password }}', 'sensitive' => true]],
            ['click' => ['selector' => 'button[type="submit"]']],
            ['waitFor' => ['any' => [
                ['selector' => '#otp', 'outcome' => 'awaiting_challenge'],
                ['selector' => '.alert-danger', 'outcome' => 'failed', 'message' => '.alert-danger'],
            ]]],
        ],

        /*
         * Step two, minutes later: the code from the email, into the browser still open from step
         * one. The session is what holds that browser; this sequence assumes nothing about how long
         * it took somebody to find the message.
         */
        'challenge' => [
            ['fill' => ['selector' => '#otp', 'value' => '{{ secret.code }}', 'sensitive' => true]],
            ['click' => ['selector' => 'button[type="submit"]']],
            ['waitFor' => ['any' => [
                ['selector' => 'table.mesaje', 'outcome' => 'authenticated'],
                ['selector' => '.alert-danger', 'outcome' => 'failed', 'message' => '.alert-danger'],
            ]]],
        ],

        /*
         * The inbox, as rows rather than HTML.
         *
         * The field names are this application's, not the portal's: `SpvInboxMessage` is what the
         * import expects, and a playbook that renamed them would move the mapping somewhere nobody
         * looks for it.
         */
        'inbox' => [
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
