<?php

declare(strict_types=1);

// Mesajul trimis unei adrese care are deja cont, când cineva încearcă să o înregistreze din nou.
return [
    'attempt' => [
        'subject' => 'Cineva a încercat să creeze un cont cu adresa ta',
        'tried' => 'Cineva a încercat să creeze un cont Kern nou cu această adresă de email. Adresa are deja un cont, așa că nu s-a creat nimic și nu s-a schimbat nimic.',
        'if_you' => 'Dacă ai fost tu, nu trebuie să faci nimic: autentifică-te cu contul pe care îl ai deja.',
        'if_not' => 'Dacă nu ai fost tu, poți ignora acest mesaj. Contul și parola ta au rămas neschimbate.',
    ],
];
