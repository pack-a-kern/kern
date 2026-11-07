<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Kern\Legislation\Legislatie\Exceptions\PortalAnswerException;
use RuntimeException;

/**
 * Finds acts on legislatie.just.ro — the searches a person picks an act from, and the import's own
 * look-up of the act it was asked for.
 *
 * Asked the way the settings say (`sources.entries.LEGISLATIE.search`): the SOAP service, or the site's
 * search page in the browser worker. Decided on each search, so a change on the settings screen reaches
 * a queue worker already running; and only that way — a search that fails is reported, never asked the
 * other way, so a person always knows which one is not working.
 *
 * A search by year and number answers with every act of that number that year — a decree, a
 * decision, a law — so {@see find()} keeps the one whose type matches, compared without diacritics,
 * and refuses rather than guesses when that is not exactly one. A type is not always enough: "HOTĂRÂRE
 * nr. 4/2021" is the Government's and ten councils' and courts'. Where several remain, the portal id
 * a search result carries picks one; it is never needed, and so never compared, when the type is
 * enough.
 */
final readonly class PortalSearch
{
    public function __construct(
        private SoapPortalSearch $soap,
        private BrowserPortalSearch $browser,
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
            $this->method()->byNumber($number, $year),
            static fn (PortalAct $act): bool => $act->number === (string) $number && $act->year() === $year,
        ));
    }

    /**
     * The acts whose title holds these words — "salariului de bază minim brut" — newest first as the
     * portal ranks them, of the given year only if one is given.
     *
     * The year is compared here, on each act, whichever way was asked: neither the service's year
     * field nor the site's date range is the act's own year to the day.
     *
     * @return list<PortalAct>
     */
    public function byTitle(string $words, ?int $year = null): array
    {
        return array_values(array_filter(
            $this->method()->byTitle($words, $year),
            static fn (PortalAct $act): bool => $year === null || $act->year() === $year,
        ));
    }

    private function method(): PortalSearchMethod
    {
        return match ($this->config->get('sources.entries.LEGISLATIE.search')) {
            'soap' => $this->soap,
            'browser' => $this->browser,
            default => throw new RuntimeException('sources.entries.LEGISLATIE.search must be "soap" or "browser".'),
        };
    }
}
