<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Legislatie;

use App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher;
use App\Domain\TaxKnowledge\Legislatie\Exceptions\PortalAnswerException;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Finds an act on legislatie.just.ro through the portal's SOAP web service.
 *
 * Only to find it. The service's own text for the Codul fiscal is a 189-character header — it
 * answers with the act as first published, not as consolidated — and where its text is whole it
 * marks articles only. What it gives reliably is the act's link and its description; everything
 * else is read from the portal's pages ({@see ConsolidationPages}).
 *
 * A search by year and number answers with every act of that number that year — a decree, a
 * decision, a law — so {@see find()} keeps the one whose type matches, compared without diacritics,
 * and refuses rather than guesses when that is not exactly one.
 */
final readonly class PortalSearch
{
    private const string SOURCE = 'LEGISLATIE';

    private const string SERVICE_NAMESPACE = 'http://tempuri.org/';

    private const string DATA_NAMESPACE = 'http://schemas.datacontract.org/2004/07/FreeWebService';

    /** Results asked for per page. The service answers ten at most, whatever is asked. */
    private const int PAGE_SIZE = 10;

    /** Pages read before giving up: an act number is shared by a handful of acts a year, not hundreds. */
    private const int MAX_PAGES = 20;

    public function __construct(
        private AllowlistedHttpFetcher $fetcher,
        private ConfigRepository $config,
    ) {
    }

    public function find(string $type, int $number, int $year): PortalAct
    {
        $token = $this->token();
        $wanted = PortalAct::key($type);
        $found = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $acts = $this->searchPage($token, $number, $year, $page);

            if ($acts === []) {
                break;
            }

            foreach ($acts as $act) {
                if ($act->typeKey() === $wanted && $act->number === (string) $number && $act->year() === $year) {
                    $found[$act->link] = $act;
                }
            }
        }

        if (count($found) !== 1) {
            throw PortalAnswerException::notExactlyOne($type, $number, $year, count($found));
        }

        return array_values($found)[0];
    }

    private function token(): string
    {
        $document = $this->call('GetToken', '<tem:GetToken/>');
        $token = $document->getElementsByTagNameNS(self::SERVICE_NAMESPACE, 'GetTokenResult')->item(0)?->textContent;

        return is_string($token) && $token !== '' ? $token : throw PortalAnswerException::noToken();
    }

    /** @return list<PortalAct> */
    private function searchPage(string $token, int $number, int $year, int $page): array
    {
        $document = $this->call('Search', sprintf(
            '<tem:Search><tem:SearchModel><fws:NumarPagina>%d</fws:NumarPagina><fws:RezultatePagina>%d</fws:RezultatePagina>'
            . '<fws:SearchAn>%d</fws:SearchAn><fws:SearchNumar>%d</fws:SearchNumar></tem:SearchModel>'
            . '<tem:tokenKey>%s</tem:tokenKey></tem:Search>',
            $page,
            self::PAGE_SIZE,
            $year,
            $number,
            htmlspecialchars($token, ENT_XML1),
        ));

        $acts = [];

        foreach ($document->getElementsByTagNameNS(self::DATA_NAMESPACE, 'Legi') as $entry) {
            $acts[] = $this->act($entry);
        }

        return $acts;
    }

    private function act(DOMElement $entry): PortalAct
    {
        $field = function (string $name) use ($entry): ?string {
            $value = $entry->getElementsByTagNameNS(self::DATA_NAMESPACE, $name)->item(0)?->textContent;
            $value = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

            return $value === '' ? null : $value;
        };

        $title = new PortalActTitle($field('Titlu') ?? '');
        $inForce = $field('DataVigoare');

        return new PortalAct(
            typeLabel: $field('TipAct') ?? '',
            number: $field('Numar') ?? '',
            title: $title->name(),
            // The title's, which keeps its diacritics; the field's own loses them.
            issuer: $title->issuer() ?? $field('Emitent'),
            actDate: $title->actDate(),
            officialJournal: $field('Publicatie'),
            officialJournalNumber: $title->journalNumber(),
            publishedOn: $title->publishedOn(),
            inForceFrom: $inForce === null ? null : (DateTimeImmutable::createFromFormat('!Y-m-d', substr($inForce, 0, 10)) ?: null),
            link: $field('LinkHtml') ?? '',
        );
    }

    /** One SOAP call, answered as a parsed document; a fault is refused with its own message. */
    private function call(string $action, string $body): DOMDocument
    {
        $envelope = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:tem="' . self::SERVICE_NAMESPACE . '" xmlns:fws="' . self::DATA_NAMESPACE . '">'
            . '<soap:Body>' . $body . '</soap:Body></soap:Envelope>';

        $answer = $this->fetcher->post(self::SOURCE, $this->config->string('sources.entries.' . self::SOURCE . '.endpoints.soap'), $envelope, [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '"' . self::SERVICE_NAMESPACE . 'IFreeWebService/' . $action . '"',
        ]);

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            // No network access and no entity expansion: the answer is data from outside.
            $loaded = $document->loadXML($answer->body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw PortalAnswerException::unreadable($action);
        }

        $faultNode = $document->getElementsByTagName('faultstring')->item(0) ?? $document->getElementsByTagName('Message')->item(0);
        $fault = $faultNode === null ? '' : trim($faultNode->textContent);

        if ($fault !== '') {
            throw PortalAnswerException::fault($action, $fault);
        }

        return $document;
    }
}
