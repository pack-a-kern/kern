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
 * The portal pages that lead from an act to its consolidations.
 *
 * Followed exactly as a reader clicks through them, checked against the Codul fiscal on 2026-09-22:
 *
 *  1. The act's own page, in its base form (`?isFormaDeBaza=True&rep=True`) — `DetaliiDocument/171280`
 *     for LEGE 227/2015. Its "istoric consolidări" list (`div#istoric_fa`) holds one link per
 *     consolidation, titled "Consolidarea din 08.08.2026" — 154 of them, 2016 to now.
 *  2. The one reference the portal puts in the form's own box, `div#div_Formaconsolidata span.S_REF
 *     a`: for this law, "COD FISCAL", `DetaliiDocumentAfis/171282`. The law's consolidations hold
 *     only that reference too, each to the code as it stood that day — 194295 of 2018 to 194296.
 *     The code's own page lists 91 consolidations and none from December 2016 to 2021, which is why
 *     the act's own list comes first.
 *
 * An act never amended has no consolidated form: its page holds its whole text, and that text is its
 * only version (the government's decision setting the minimum wage, `HOTĂRÂRE nr. 1.506/2024`,
 * checked on 2026-09-22). Every other `DetaliiDocumentAfis` link on such a page is an act it cites —
 * "În temeiul art. 108 din Constituția României" — never its own form, so only the reference in the
 * form's box is followed.
 *
 * Nothing is inferred from the law's text: the dates are the portal's own, from the list's titles.
 */
final readonly class ConsolidationPages
{
    private const string SOURCE = 'LEGISLATIE';

    private const string CONSOLIDATION_TITLE = '/^\s*Consolidarea\s+din\s+(\d{1,2})\.(\d{1,2})\.(\d{4})\s*$/u';

    private const string CONSOLIDATED_FORM_PATH = '~/Public/DetaliiDocumentAfis/(\d+)~';

    /** The act's own reference to its consolidated form — the only link that is not a citation. */
    private const string OWN_FORM_REFERENCE = '//*[@id="div_Formaconsolidata"]//span[contains(concat(" ", normalize-space(@class), " "), " S_REF ")]//a[@href]';

    private const string DOCUMENT_PATH = '~/Public/DetaliiDocument(?:Afis)?/(\d+)~';

    public function __construct(
        private AllowlistedHttpFetcher $fetcher,
        private ConfigRepository $config,
    ) {
    }

    /**
     * The consolidations of an act, from its link as the web service gives it — or null for an act
     * never amended, whose own page is its one version.
     *
     * An act's own page lists its consolidations where it has them — LEGE 227/2015 lists 154, from
     * 2016 to now. Where the act is the law approving a code, that page holds only the law's two
     * articles and a reference to the code (`S_REF` "COD FISCAL"), and so does each consolidation it
     * lists: those are read through their reference ({@see Consolidation::$throughReference}).
     * Without a list of its own, the act's reference leads to the code's, whose own list may be the
     * shorter one — the Codul fiscal's lists 91, none between December 2016 and 2021.
     */
    public function forAct(string $actLink): ?ActConsolidations
    {
        $page = $this->actPageUrl($actLink);
        $xpath = new DOMXPath($this->load($this->fetcher->fetch(self::SOURCE, $page . '?isFormaDeBaza=True&rep=True')->body));
        $reference = $this->reference($xpath);
        $own = $this->listed($xpath, $reference !== null);

        if ($own !== []) {
            return new ActConsolidations($page, $own);
        }

        if ($reference === null) {
            return null;
        }

        $form = $this->portalUrl('/Public/DetaliiDocumentAfis/' . $reference);
        $listed = $this->listed(new DOMXPath($this->load($this->fetcher->fetch(self::SOURCE, $form)->body)), false);

        return $listed === [] ? throw PortalAnswerException::noConsolidations($form) : new ActConsolidations($form, $listed);
    }

    /** The act's own page on the portal: its text as it stands, for an act never consolidated. */
    public function actPageUrl(string $actLink): string
    {
        return $this->portalUrl($actLink);
    }

    /**
     * The text a consolidation that is only a reference stands for: the code as it stood that day.
     * Null when its page refers to nothing.
     */
    public function referencedText(string $consolidationUrl): ?string
    {
        $reference = $this->reference(new DOMXPath($this->load($this->fetcher->fetch(self::SOURCE, $consolidationUrl)->body)));

        return $reference === null ? null : $this->portalUrl('/Public/DetaliiDocument/' . $reference);
    }

    /** The portal id the form's own reference points at — never a citation of another act. */
    private function reference(DOMXPath $xpath): ?string
    {
        foreach ($xpath->query(self::OWN_FORM_REFERENCE) ?: [] as $anchor) {
            if ($anchor instanceof DOMElement && preg_match(self::CONSOLIDATED_FORM_PATH, $anchor->getAttribute('href'), $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Every consolidation a page's "istoric consolidări" lists, oldest first.
     *
     * @return list<Consolidation>
     */
    private function listed(DOMXPath $xpath, bool $throughReference): array
    {
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

            $found[$ref[1]] = new Consolidation($day, $ref[1], $this->portalUrl('/Public/DetaliiDocument/' . $ref[1]), $throughReference);
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
