<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Sleep;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
use Kern\Legislation\Fetching\Exceptions\UnexpectedStatusException;
use Kern\Legislation\Fetching\FetchedDocument;
use Kern\Legislation\Legislatie\Exceptions\PortalAnswerException;

/**
 * Searches legislatie.just.ro through the portal's SOAP web service — one of the two ways
 * {@see PortalSearch} can ask, and the default.
 *
 * Only to find an act. The service's own text for the Codul fiscal is a 189-character header — it
 * answers with the act as first published, not as consolidated — and where its text is whole it
 * marks articles only. What it gives reliably is the act's link and its description; everything
 * else is read from the portal's pages ({@see ConsolidationPages}).
 */
final readonly class SoapPortalSearch implements PortalSearchMethod
{
    private const string SOURCE = 'LEGISLATIE';

    private const string SERVICE_NAMESPACE = 'http://tempuri.org/';

    private const string DATA_NAMESPACE = 'http://schemas.datacontract.org/2004/07/FreeWebService';

    public function __construct(
        private AllowlistedHttpFetcher $fetcher,
        private ConfigRepository $config,
    ) {
    }

    /**
     * One page of the service's results — at most ten, whatever is asked for above that (checked
     * 2026-11-09, asked for fifty), which is `soap_page_size`. Pages count from 1; asked for page 0, the
     * service answers with page 1 again.
     *
     * A number is asked with its year, which the service honours. A title is asked without one: beside a
     * title the service ignores its year field (asked for 2020, it answers with 2026's acts), so
     * {@see PortalQuery::keeps()} compares the year on each act. Diacritics are the service's to match,
     * and it does: "ţară", "țară" and "tara" find the same acts (checked 2026-09-22).
     */
    public function page(PortalQuery $query, int $page): PortalPage
    {
        $pageSize = $this->config->integer('sources.entries.' . self::SOURCE . '.soap_page_size');
        $criteria = $query->number !== null && $query->year !== null
            ? sprintf('<fws:SearchAn>%d</fws:SearchAn><fws:SearchNumar>%d</fws:SearchNumar>', $query->year, $query->number)
            : '<fws:SearchTitlu>' . htmlspecialchars((string) $query->words, ENT_XML1) . '</fws:SearchTitlu>';

        $document = $this->call('Search', sprintf(
            '<tem:Search><tem:SearchModel><fws:NumarPagina>%d</fws:NumarPagina><fws:RezultatePagina>%d</fws:RezultatePagina>%s</tem:SearchModel>'
            . '<tem:tokenKey>%s</tem:tokenKey></tem:Search>',
            $page,
            $pageSize,
            $criteria,
            htmlspecialchars($this->token(), ENT_XML1),
        ));

        $acts = [];

        foreach ($document->getElementsByTagNameNS(self::DATA_NAMESPACE, 'Legi') as $entry) {
            $acts[] = $this->act($entry);
        }

        return new PortalPage($acts, count($acts) >= $pageSize);
    }

    private function token(): string
    {
        $document = $this->call('GetToken', '<tem:GetToken/>');
        $token = $document->getElementsByTagNameNS(self::SERVICE_NAMESPACE, 'GetTokenResult')->item(0)?->textContent;

        return is_string($token) && $token !== '' ? $token : throw PortalAnswerException::noToken();
    }

    private function act(DOMElement $entry): PortalAct
    {
        $field = function (string $name) use ($entry): ?string {
            $value = $entry->getElementsByTagNameNS(self::DATA_NAMESPACE, $name)->item(0)?->textContent;
            $value = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

            return $value === '' ? null : $value;
        };

        $inForce = $field('DataVigoare');

        return PortalAct::fromTitle(
            typeLabel: $field('TipAct') ?? '',
            number: $field('Numar') ?? '',
            title: $field('Titlu') ?? '',
            issuer: $field('Emitent'),
            officialJournal: $field('Publicatie'),
            inForceFrom: $inForce === null ? null : (DateTimeImmutable::createFromFormat('!Y-m-d', substr($inForce, 0, 10)) ?: null),
            link: $field('LinkHtml') ?? '',
        );
    }

    /**
     * The envelope sent, and sent again when the service answers with a server error: it answers the same
     * call with HTTP 500 and with its result moments apart (2026-11-09: pages one to three of one search
     * refused and answered by turns), and asking again is what brings the result. Any other status, and
     * a 500 on the last try, is the caller's to see.
     */
    private function post(string $envelope, string $action): FetchedDocument
    {
        $retries = $this->config->integer('sources.entries.' . self::SOURCE . '.search_refused_retries');
        $pause = $this->config->integer('sources.entries.' . self::SOURCE . '.search_refused_pause_ms');

        for ($try = 0; ; $try++) {
            try {
                return $this->fetcher->post(self::SOURCE, $this->config->string('sources.entries.' . self::SOURCE . '.endpoints.soap'), $envelope, [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction' => '"' . self::SERVICE_NAMESPACE . 'IFreeWebService/' . $action . '"',
                ]);
            } catch (UnexpectedStatusException $refused) {
                if ($refused->status < 500 || $try >= $retries) {
                    throw $refused;
                }

                Sleep::for($pause)->milliseconds();
            }
        }
    }

    /** One SOAP call, answered as a parsed document; a fault is refused with its own message. */
    private function call(string $action, string $body): DOMDocument
    {
        $envelope = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" '
            . 'xmlns:tem="' . self::SERVICE_NAMESPACE . '" xmlns:fws="' . self::DATA_NAMESPACE . '">'
            . '<soap:Body>' . $body . '</soap:Body></soap:Envelope>';

        $answer = $this->post($envelope, $action);

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
