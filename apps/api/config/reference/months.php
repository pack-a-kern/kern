<?php

declare(strict_types=1);

/*
 * Month names, for dates a bank writes as "12 septembrie 2026".
 *
 * In configuration because they are language, not logic: a Hungarian or German export needs its
 * own list, and adding one must not be a code change. Compared without diacritics and in lower
 * case, so "Ştefan"-style spellings and shouty exports both match.
 */
return [
    'ianuarie' => 1, 'ian' => 1, 'january' => 1, 'jan' => 1,
    'februarie' => 2, 'feb' => 2, 'february' => 2,
    'martie' => 3, 'mar' => 3, 'march' => 3,
    'aprilie' => 4, 'apr' => 4, 'april' => 4,
    'mai' => 5, 'may' => 5,
    'iunie' => 6, 'iun' => 6, 'june' => 6, 'jun' => 6,
    'iulie' => 7, 'iul' => 7, 'july' => 7, 'jul' => 7,
    'august' => 8, 'aug' => 8,
    'septembrie' => 9, 'sep' => 9, 'sept' => 9, 'september' => 9,
    'octombrie' => 10, 'oct' => 10, 'october' => 10,
    'noiembrie' => 11, 'noi' => 11, 'nov' => 11, 'november' => 11,
    'decembrie' => 12, 'dec' => 12, 'december' => 12,
];
