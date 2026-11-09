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
 * search page in the browser worker. Decided on each page, so a change on the settings screen reaches a
 * queue worker already running; and only that way — a page that fails is reported, never asked the
 * other way, so a person always knows which one is not working.
 *
 * Two ways to read a search. {@see page()} reads one page, for a search queued a page a job
 * (`Jobs\ReadPortalSearchPage`, ADR 0024), which outlives any request. {@see all()} reads every page
 * at once, for whoever can wait — the import's look-up, a console command, another package's job. Both
 * end the reading by the same rule, {@see isLast()}, and keep acts by the same one,
 * {@see PortalQuery::keeps()}, so the two cannot answer differently.
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
     *
     * A search by year and number answers with every act of that number that year — a decree, a
     * decision, a law — so this keeps the one whose type matches, and refuses rather than guesses when
     * that is not exactly one. A type is not always enough: "HOTĂRÂRE nr. 4/2021" is the Government's
     * and ten councils' and courts'. Where several remain, the portal id a search result carries picks
     * one; it is never needed, and so never compared, when the type is enough.
     */
    public function find(string $type, int $number, int $year, ?string $portalId = null): PortalAct
    {
        $found = $this->all(PortalQuery::byNumber($number, $year, $type));

        if (count($found) > 1 && $portalId !== null) {
            $found = array_values(array_filter($found, static fn (PortalAct $act): bool => $act->portalId() === $portalId));
        }

        if (count($found) !== 1) {
            throw PortalAnswerException::notExactlyOne($type, $number, $year, count($found));
        }

        return $found[0];
    }

    /**
     * Every act numbered so that year, of any type: a decree, a council's decision, a law.
     *
     * @return list<PortalAct>
     */
    public function byNumber(int $number, int $year): array
    {
        return $this->all(PortalQuery::byNumber($number, $year));
    }

    /**
     * The acts whose title holds these words — "salariului de bază minim brut" — newest first as the
     * portal ranks them, of the given year only if one is given.
     *
     * @return list<PortalAct>
     */
    public function byTitle(string $words, ?int $year = null): array
    {
        return $this->all(PortalQuery::byTitle($words, $year));
    }

    /**
     * Every act this query asks for, page after page, each once — within the search's own time
     * (`search_deadline_seconds`), past which no further page is asked and the acts found are the answer.
     *
     * A page the portal still fails after the method's retries is thrown, whichever page it is: an answer
     * missing a page it does not say it is missing is worse than none. The site answers a page past the
     * last with an empty list, so a refusal is never where the results end.
     *
     * @return list<PortalAct>
     */
    public function all(PortalQuery $query): array
    {
        $deadline = microtime(true) + $this->config->integer('sources.entries.LEGISLATIE.search_deadline_seconds');
        $found = [];
        $previous = [];

        for ($number = 1; ; $number++) {
            $page = $this->page($query, $number);

            foreach ($page->acts as $act) {
                if ($query->keeps($act)) {
                    $found[$act->link] ??= $act;
                }
            }

            if ($this->isLast($page, $number, $previous) || microtime(true) >= $deadline) {
                break;
            }

            $previous = $page->links();
        }

        return array_values($found);
    }

    /** One page of results, as the portal listed it — {@see PortalQuery::keeps()} says which to keep. */
    public function page(PortalQuery $query, int $number): PortalPage
    {
        return $this->method()->page($query, $number);
    }

    /**
     * Whether no page should be read after this one: it was shorter than a full page; it listed nothing
     * the page before had not — what the service answers past its last page; or it was the last page a
     * search may read (`search_max_pages`). Neither the site nor the service says how many results there
     * are, so this is how a reading ends.
     *
     * @param  list<string>  $previousLinks  the links the page before listed; none before the first
     */
    public function isLast(PortalPage $page, int $number, array $previousLinks): bool
    {
        return ! $page->full
            || array_diff($page->links(), $previousLinks) === []
            || $number >= $this->config->integer('sources.entries.LEGISLATIE.search_max_pages');
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
