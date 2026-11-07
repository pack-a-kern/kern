<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Fetching\AllowlistedHttpFetcher;
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

    public function byNumber(int $number, int $year): array
    {
        return $this->collect(sprintf('<fws:SearchAn>%d</fws:SearchAn><fws:SearchNumar>%d</fws:SearchNumar>', $year, $number));
    }

    /**
     * The year is not asked of the service: its own year field is not the act's year (asked for 2024,
     * it answers with acts of 2025 and 2026 too), so {@see PortalSearch} compares it on each act.
     * Diacritics are the service's to match, and it does: "ţară", "țară" and "tara" find the same acts
     * (checked 2026-09-22).
     */
    public function byTitle(string $words, ?int $year): array
    {
        return $this->collect('<fws:SearchTitlu>' . htmlspecialchars($words, ENT_XML1) . '</fws:SearchTitlu>');
    }

    private function token(): string
    {
        $document = $this->call('GetToken', '<tem:GetToken/>');
        $token = $document->getElementsByTagNameNS(self::SERVICE_NAMESPACE, 'GetTokenResult')->item(0)?->textContent;

        return is_string($token) && $token !== '' ? $token : throw PortalAnswerException::noToken();
    }

    /**
     * Every result for these criteria, page after page, each act once.
     *
     * Pages count from 1 — asked for page 0, the service answers with page 1 again. A page that adds
     * nothing new ends the reading, as an empty one does.
     *
     * @return list<PortalAct>
     */
    private function collect(string $criteria): array
    {
        $token = $this->token();
        $found = [];

        $pageSize = $this->config->integer('sources.entries.' . self::SOURCE . '.search_page_size');
        $maxPages = $this->config->integer('sources.entries.' . self::SOURCE . '.search_max_pages');

        for ($page = 1; $page <= $maxPages; $page++) {
            $document = $this->call('Search', sprintf(
                '<tem:Search><tem:SearchModel><fws:NumarPagina>%d</fws:NumarPagina><fws:RezultatePagina>%d</fws:RezultatePagina>%s</tem:SearchModel>'
                . '<tem:tokenKey>%s</tem:tokenKey></tem:Search>',
                $page,
                $pageSize,
                $criteria,
                htmlspecialchars($token, ENT_XML1),
            ));

            $before = count($found);

            foreach ($document->getElementsByTagNameNS(self::DATA_NAMESPACE, 'Legi') as $entry) {
                $act = $this->act($entry);
                $found[$act->link] ??= $act;
            }

            if (count($found) === $before) {
                break;
            }
        }

        return array_values($found);
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
