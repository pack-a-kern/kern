<?php

declare(strict_types=1);

/*
 * legislatie.just.ro through the browser worker (`apps/browser-worker`): the site's own search page,
 * and a law's pages opened as a browser opens them.
 *
 * Used only when the settings say so (`sources.entries.LEGISLATIE.search` / `.pages`), and never as a
 * fallback for the SOAP service or the direct download: each way is used alone and says when it fails.
 *
 * Everything here is a fact about the site, observed on 2026-11-07 in the worker's own Chromium. When
 * the site changes, this file is what changes — no PHP, and no rebuild of the worker.
 *
 *  - The search form only builds an address. `/Public/RezultateCautare?titlu=…` is the results page
 *    itself, ten results to a page, the next at `&page=2`; so a search is opening that address, with
 *    nothing to type or click. The first page is asked without `page`, as the form asks it: `&page=1`
 *    answered 500 where the same search without it answered its ten results.
 *  - A number and its year are asked as "227-2015", the form the site's own number field documents
 *    ("numar-an ex: 19-2000"). The signing-date range with a number answered 500 on every try.
 *  - A title's year is the signing-date range with the year alone at both ends, which narrows the
 *    results on the site. A full date there ("2015/01/01") answered 500. The year is compared again
 *    on each act, as it is for the SOAP service.
 *  - Each result is a `div.search_result_item`: a link to the act, then a paragraph carrying the same
 *    title line the SOAP service's `Titlu` does — name, EMITENT, PUBLICAT ÎN — then "Data intrarii in
 *    vigoare: 01 ianuarie 2016".
 *  - A search with no result, and a search the site could not run, are the same page: HTTP 500 with
 *    its "Pagina nu a fost gasita" error. Nothing on it tells them apart, so neither is claimed.
 */
return [
    /*
     * What the worker is sent, whole. `{{ secret.url }}` is filled with the address built below — the
     * worker redacts it from anything it says afterwards, which costs nothing for a search term.
     */
    'playbook' => [
        'version' => 1,
        'name' => 'legislatie',

        // The portal and nothing else, checked before every navigation and on every redirect.
        'allow' => ['legislatie.just.ro'],

        // A results page and a law are text. Its pictures, fonts and styles are not read.
        'context' => [
            'block' => ['image', 'font', 'stylesheet', 'media'],
        ],

        'sequences' => [
            'search' => [
                ['goto' => '{{ secret.url }}', 'timeout' => 60000],
                [
                    'waitFor' => [
                        'any' => [
                            ['selector' => '.search_result_item', 'outcome' => 'found', 'continue' => true],
                            // A page of results with none on it: the list is simply empty.
                            ['selector' => 'body:not(:has(.search_result_item)):not(:has(.error-404))', 'outcome' => 'none'],
                            // Nothing matched, or the search failed — the site says both with this page.
                            ['selector' => '.error-404', 'outcome' => 'refused'],
                        ],
                        'timeout' => 15000,
                    ],
                ],
                [
                    'extract' => [
                        'rows' => '.search_result_item',
                        'fields' => [
                            // As shown, not as marked up: the name and the "privind …" after it are
                            // separated by a <br>, which the markup's text runs together.
                            'title' => ['selector' => ':scope > p:nth-of-type(2)', 'rendered' => true],
                            'in_force' => ['selector' => ':scope > p:nth-of-type(3)', 'rendered' => true],
                            'link' => ['selector' => ':scope > p:nth-of-type(1) a', 'attr' => 'href'],
                        ],
                        'into' => 'acts',
                    ],
                ],
            ],

            'page' => [
                ['goto' => '{{ secret.url }}', 'timeout' => 120000],
                ['source' => true],
            ],
        ],
    ],

    // How a search becomes the results page's address, and what each row says.
    'search' => [
        'url' => 'https://legislatie.just.ro/Public/RezultateCautare',
        'title_parameter' => 'titlu',
        'number_parameter' => 'numardoc',
        // `{number}` and `{year}`: the site's own "numar-an".
        'number_format' => '{number}-{year}',
        'year_from_parameter' => 'semnatinceputtext',
        'year_to_parameter' => 'semnatsfarsittext',
        // Sent from this page on; the pages before it are asked without it (see above).
        'page_parameter' => 'page',
        'page_parameter_from' => 2,

        /*
         * The act's type and number, at the start of its title: "LEGE nr. 227 din 8 septembrie 2015 …",
         * "HOTĂRÂRE nr. 4 din …", "CODUL FISCAL din 22 decembrie 2003 …" (no number). Read from the
         * title rather than the link's "5. LEGE 227 08/09/2015", which abbreviates — "HG" for HOTĂRÂRE —
         * where the title spells the type the import compares against.
         */
        'heading_pattern' => '/^(?<type>.+?)\s+(?:nr\.\s*(?<number>\S+)\s+)?din\s/u',

        /*
         * A journal as the SOAP service's own `Publicatie` field names it, by the name the results page
         * prints in its title line — in capitals there ("PUBLICAT ÎN: MONITORUL OFICIAL nr. 688 …"). So a
         * law found either way is stored under the same name. One the list does not know is kept as the
         * page prints it.
         */
        'journals' => [
            'MONITORUL OFICIAL' => 'Monitorul Oficial',
        ],
    ],
];
