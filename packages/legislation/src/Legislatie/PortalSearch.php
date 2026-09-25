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
 * Finds an act on legislatie.just.ro through the portal's SOAP web service.
 *
 * Only to find it. The service's own text for the Codul fiscal is a 189-character header — it
 * answers with the act as first published, not as consolidated — and where its text is whole it
 * marks articles only. What it gives reliably is the act's link and its description; everything
 * else is read from the portal's pages ({@see ConsolidationPages}).
 *
 * A search by year and number answers with every act of that number that year — a decree, a
 * decision, a law — so {@see find()} keeps the one whose type matches, compared without diacritics,
 * and refuses rather than guesses when that is not exactly one. A type is not always enough: "HOTĂRÂRE
 * nr. 4/2021" is the Government's and ten councils' and courts'. Where several remain, the portal id
 * a search result carries picks one; it is never needed, and so never compared, when the type is
 * enough. {@see byNumber()} and {@see byTitle()} are the searches a person picks an act from.
 */
final readonly class PortalSearch
{
    private const string SOURCE = 'LEGISLATIE';

    private const string SERVICE_NAMESPACE = 'http://tempuri.org/';

    private const string DATA_NAMESPACE = 'http://schemas.datacontract.org/2004/07/FreeWebService';

    /** Results asked for per page. The service answers ten at most, whatever is asked. */
    private const int PAGE_SIZE = 10;

    /** Pages read before giving up: an act number is shared by a handful of acts a year, and a title search is read to 200 acts at most. */
    private const int MAX_PAGES = 20;

    public function __construct(
        private AllowlistedHttpFetcher $fetcher,
        private ConfigRepository $config,
    ) {
    }

    /**
     * The one act of this type, number and year — or, where several share them, the one with this
     * portal id, as a search result names it.
     */
    public function find(string $type, int $number, int $year, ?string $portalId = null): PortalAct
    {
        $wanted = PortalAct::key($type);
        $found = array_filter($this->byNumber($number, $year), static fn (PortalAct $act): bool => $act->typeKey() === $wanted);

        if (count($found) > 1 && $portalId !== null) {
            $found = array_filter($found, static fn (PortalAct $act): bool => $act->portalId() === $portalId);
        }

        if (count($found) !== 1) {
            throw PortalAnswerException::notExactlyOne($type, $number, $year, count($found));
        }

        return array_values($found)[0];
    }

    /**
     * Every act numbered so that year, of any type: a decree, a council's decision, a law.
     *
     * @return list<PortalAct>
     */
    public function byNumber(int $number, int $year): array
    {
        return array_values(array_filter(
            $this->collect(sprintf('<fws:SearchAn>%d</fws:SearchAn><fws:SearchNumar>%d</fws:SearchNumar>', $year, $number)),
            static fn (PortalAct $act): bool => $act->number === (string) $number && $act->year() === $year,
        ));
    }

    /**
     * The acts whose title holds these words — "salariului de bază minim brut" — newest first as the
     * portal ranks them, of the given year only if one is given.
     *
     * The year is compared here, not asked of the service: its own year field is not the act's year
     * (asked for 2024, it answers with acts of 2025 and 2026 too). Diacritics are the service's to
     * match, and it does: "ţară", "țară" and "tara" find the same acts (checked 2026-09-22).
     *
     * @return list<PortalAct>
     */
    public function byTitle(string $words, ?int $year = null): array
    {
        return array_values(array_filter(
            $this->collect('<fws:SearchTitlu>' . htmlspecialchars($words, ENT_XML1) . '</fws:SearchTitlu>'),
            static fn (PortalAct $act): bool => $year === null || $act->year() === $year,
        ));
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

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $document = $this->call('Search', sprintf(
                '<tem:Search><tem:SearchModel><fws:NumarPagina>%d</fws:NumarPagina><fws:RezultatePagina>%d</fws:RezultatePagina>%s</tem:SearchModel>'
                . '<tem:tokenKey>%s</tem:tokenKey></tem:Search>',
                $page,
                self::PAGE_SIZE,
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
