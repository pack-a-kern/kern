<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Legislatie;

use App\Domain\TaxKnowledge\Fetching\AllowlistedHttpFetcher;
use App\Domain\TaxKnowledge\Legislatie\Exceptions\PortalAnswerException;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The two portal pages that lead from an act to its consolidations.
 *
 * Followed exactly as a reader clicks through them, checked against the Codul fiscal on 2026-09-22:
 *
 *  1. The act's own page, in its base form (`?isFormaDeBaza=True&rep=True`) — `DetaliiDocument/171280`.
 *     It links to the consolidated form, `DetaliiDocumentAfis/171282`.
 *  2. The consolidated form. Its "istoric consolidări" list (`div#istoric_fa`) holds one link per
 *     consolidation, titled "Consolidarea din 08.08.2026" and pointing at that consolidation's own
 *     page, `DetaliiDocument/312601`. The Codul fiscal has 91.
 *
 * Nothing is inferred from the law's text: the dates are the portal's own, from the list's titles.
 */
final readonly class ConsolidationPages
{
    private const string SOURCE = 'LEGISLATIE';

    private const string CONSOLIDATION_TITLE = '/^\s*Consolidarea\s+din\s+(\d{1,2})\.(\d{1,2})\.(\d{4})\s*$/u';

    private const string CONSOLIDATED_FORM_PATH = '~/Public/DetaliiDocumentAfis/(\d+)~';

    private const string DOCUMENT_PATH = '~/Public/DetaliiDocument(?:Afis)?/(\d+)~';

    public function __construct(
        private AllowlistedHttpFetcher $fetcher,
        private ConfigRepository $config,
    ) {
    }

    /** The consolidated form's address, from the act's link as the web service gives it. */
    public function consolidatedFormUrl(string $actLink): string
    {
        $basePage = $this->portalUrl($actLink) . '?isFormaDeBaza=True&rep=True';

        foreach ($this->links($this->fetcher->fetch(self::SOURCE, $basePage)->body) as $href) {
            if (preg_match(self::CONSOLIDATED_FORM_PATH, $href, $match) === 1) {
                return $this->portalUrl('/Public/DetaliiDocumentAfis/' . $match[1]);
            }
        }

        throw PortalAnswerException::noConsolidatedForm($basePage);
    }

    /**
     * Every consolidation the consolidated form lists, oldest first.
     *
     * @return list<Consolidation>
     */
    public function consolidations(string $consolidatedFormUrl): array
    {
        $xpath = new DOMXPath($this->load($this->fetcher->fetch(self::SOURCE, $consolidatedFormUrl)->body));
        $found = [];

        foreach ($xpath->query('//*[@id="istoric_fa"]//a') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $title = $anchor->getAttribute('title');
            $href = $anchor->getAttribute('href');

            if (preg_match(self::CONSOLIDATION_TITLE, $title, $date) !== 1 || preg_match(self::DOCUMENT_PATH, $href, $ref) !== 1) {
                continue;
            }

            $day = DateTimeImmutable::createFromFormat('!Y-n-j', "{$date[3]}-{$date[2]}-{$date[1]}");

            if ($day === false || ! checkdate((int) $date[2], (int) $date[1], (int) $date[3])) {
                continue;
            }

            $found[$ref[1]] = new Consolidation($day, $ref[1], $this->portalUrl('/Public/DetaliiDocument/' . $ref[1]));
        }

        if ($found === []) {
            throw PortalAnswerException::noConsolidations($consolidatedFormUrl);
        }

        $list = array_values($found);
        usort($list, static fn (Consolidation $a, Consolidation $b): int => $a->date <=> $b->date);

        return $list;
    }

    /** The portal id in an act's link — `171280` in `…/DetaliiDocument/171280`. */
    public static function documentId(string $url): ?string
    {
        return preg_match(self::DOCUMENT_PATH, $url, $match) === 1 ? $match[1] : null;
    }

    /**
     * An address on the portal, over HTTPS, whatever form the page wrote it in.
     *
     * The web service answers with `http://`, and the pages link relatively as
     * `~/../../../Public/…`; both become the configured base plus the `/Public/…` path, so only the
     * allowlisted host is ever asked.
     */
    private function portalUrl(string $href): string
    {
        $path = preg_match('~(/Public/[^?#\s]+)~', $href, $match) === 1 ? $match[1] : $href;

        return rtrim($this->config->string('sources.entries.' . self::SOURCE . '.base_url'), '/') . $path;
    }

    /** @return list<string> */
    private function links(string $html): array
    {
        $links = [];

        foreach ((new DOMXPath($this->load($html)))->query('//a[@href]') ?: [] as $anchor) {
            if ($anchor instanceof DOMElement) {
                $links[] = $anchor->getAttribute('href');
            }
        }

        return $links;
    }

    private function load(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
