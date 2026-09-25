<?php

declare(strict_types=1);

// Words that appear in party names without saying *which* party.
//
// `Kern\Platform\Text\PartyName::sharesName()` decides whether two names printed in different places
// could be the same party by looking for a word they share. Legal forms and a few ubiquitous words
// have to be ignored for that to mean anything: "Kaufland Romania SCS" and "Lidl Romania SCS" share
// two words and are different shops, while "Kaufland Romania SCS" and "KAUFLAND 1234 BUCURESTI"
// share one and are the same shop.
//
// Lower case and without diacritics or dots, the form `PartyName::words()` produces — "S.R.L."
// arrives here as "srl".
//
// A vocabulary, not a rule, so it lives here: a gateway that writes "SUCURSALA" on every row, or a
// broker whose statements append "CLIENT", is a line in this file rather than a deployment.
return [
    'generic_words' => [
        // Legal forms, Romanian and the foreign ones most often seen on Romanian statements.
        'srl', 'scs', 'sca', 'snc', 'pfa', 'ifn',
        'ltd', 'llc', 'inc', 'plc', 'gmbh', 'bv', 'spa',
        // On nearly every Romanian merchant's name or card descriptor, and so on none in particular.
        'romania', 'bucuresti', 'sucursala', 'magazin',
    ],
];
