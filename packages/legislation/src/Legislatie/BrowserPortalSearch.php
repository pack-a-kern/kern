<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Sleep;
use Kern\Legislation\Legislatie\Exceptions\PortalAnswerException;
use Kern\Legislation\Legislatie\Exceptions\PortalBrowserException;
use Kern\Legislation\Legislatie\Exceptions\PortalSearchRefusedException;
use Kern\Legislation\Parsing\RomanianDateParser;
use Kern\Platform\BrowserWorker\BrowserWorkerClient;
use Kern\Platform\BrowserWorker\Exceptions\BrowserActionNotBuiltException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserRunFailedException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerAtCapacityException;
use Kern\Platform\BrowserWorker\Exceptions\BrowserWorkerUnavailableException;
use RuntimeException;

/**
 * Searches legislatie.just.ro on its own search page, in the browser worker — the other way
 * {@see PortalSearch} can ask, for when the SOAP service is not answering.
 *
 * The site's results page carries the same title line the service's `Titlu` does, so an act is built
 * the same way ({@see PortalAct::fromTitle()}) and nothing after the search can tell which way it came.
 * Every fact about the page — its address, its parameters, its selectors, how a title begins — is in
 * `config/playbooks/legislatie.php`; this reads what the playbook extracted.
 *
 * ## One browser for a whole search
 *
 * A search reads page after page, so it opens one session and runs the playbook's `search` sequence in
 * it once per page, then closes it whatever happened: one Chromium for the search rather than one per
 * page, and none left behind.
 */
final readonly class BrowserPortalSearch implements PortalSearchMethod
{
    private const string SOURCE = 'LEGISLATIE';

    private const string SEQUENCE = 'search';

    public function __construct(
        private BrowserWorkerClient $worker,
        private ConfigRepository $config,
    ) {
    }

    public function byNumber(int $number, int $year): array
    {
        return $this->collect([
            $this->rule('number_parameter') => strtr($this->rule('number_format'), ['{number}' => (string) $number, '{year}' => (string) $year]),
        ]);
    }

    /** The year narrows what the site sends — the signing-date range, with the year at both ends. */
    public function byTitle(string $words, ?int $year): array
    {
        $query = [$this->rule('title_parameter') => $words];

        if ($year !== null) {
            $query[$this->rule('year_from_parameter')] = (string) $year;
            $query[$this->rule('year_to_parameter')] = (string) $year;
        }

        return $this->collect($query);
    }

    /**
     * Every result for this query, page after page, each act once.
     *
     * A page with fewer than a page's worth of results is the last; so is one that adds nothing new,
     * which is what a site answering a page past its end with the last one again looks like; so is the
     * page read when the search's own time (`search_deadline_seconds`) has run out.
     *
     * @param  array<string, string>  $query
     * @return list<PortalAct>
     */
    private function collect(array $query): array
    {
        $playbook = $this->playbook();
        $pageSize = $this->config->integer('sources.entries.' . self::SOURCE . '.search_page_size');
        $maxPages = $this->config->integer('sources.entries.' . self::SOURCE . '.search_max_pages');
        $deadline = microtime(true) + $this->config->integer('sources.entries.' . self::SOURCE . '.search_deadline_seconds');

        return PortalBrowserException::translate(function () use ($playbook, $query, $pageSize, $maxPages, $deadline): array {
            $session = $this->worker->openSession($playbook);
            $sessionId = $session['session_id'] ?? null;

            if (! is_string($sessionId) || $sessionId === '') {
                throw BrowserWorkerUnavailableException::malformedResponse();
            }

            try {
                $found = [];

                for ($page = 1; $page <= $maxPages; $page++) {
                    $rows = $this->page($playbook, $sessionId, $this->onPage($query, $page), $page === 1);
                    $before = count($found);

                    foreach ($rows as $row) {
                        $act = $this->act($row);
                        $found[$act->link] ??= $act;
                    }

                    // Past the search's own time, no further page is asked: the acts found are the answer,
                    // as they are at the last page.
                    if (count($rows) < $pageSize || count($found) === $before || microtime(true) >= $deadline) {
                        break;
                    }
                }

                return array_values($found);
            } finally {
                $this->close($sessionId);
            }
        });
    }

    /**
     * The query for one page of results: the site's first page is asked without a page number.
     *
     * @param  array<string, string>  $query
     * @return array<string, string>
     */
    private function onPage(array $query, int $page): array
    {
        return $page < $this->config->integer($this->playbookKey() . '.search.page_parameter_from')
            ? $query
            : [...$query, $this->rule('page_parameter') => (string) $page];
    }

    /**
     * One results page, as the rows the playbook extracted — none when the page listed none.
     *
     * The site answers a page past the last with the same error page it answers an empty search with,
     * so only the first page's refusal is the search's: after it, a refused page is where the results
     * end, and what the pages before found is kept. Ten results exactly would otherwise read page two,
     * be refused, and lose the ten.
     *
     * @param  array<string, mixed>  $playbook
     * @param  array<string, string>  $query
     * @return list<array<string, string|null>>
     */
    private function page(array $playbook, string $sessionId, array $query, bool $first): array
    {
        $url = $this->rule('url') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC1738);
        $answer = $this->worker->runInSession($sessionId, $playbook, self::SEQUENCE, ['url' => $url]);

        // The first page, refused, is asked again — the same page, the same way, never another: the
        // site answered about half its searches with its error page on 2026-11-07 and the other half
        // with their results, the same address minutes apart. A search with no result is refused
        // every time and ends as one after the last try.
        if ($first) {
            $retries = $this->config->integer('sources.entries.' . self::SOURCE . '.search_refused_retries');
            $pause = $this->config->integer('sources.entries.' . self::SOURCE . '.search_refused_pause_ms');

            for ($try = 0; ($answer['outcome'] ?? null) === 'refused' && $try < $retries; $try++) {
                Sleep::for($pause)->milliseconds();
                $answer = $this->worker->runInSession($sessionId, $playbook, self::SEQUENCE, ['url' => $url]);
            }
        }

        return match ($answer['outcome'] ?? null) {
            'found' => $this->rows($answer),
            'none' => [],
            'refused' => $first ? throw PortalSearchRefusedException::create() : [],
            default => throw PortalAnswerException::unreadable('search'),
        };
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array<string, string|null>>
     */
    private function rows(array $answer): array
    {
        $data = $answer['data'] ?? null;
        $acts = is_array($data) ? ($data['acts'] ?? null) : null;

        if (! is_array($acts)) {
            // "Found" and nothing extracted: the playbook's selectors disagree with the page, which is
            // not an empty result and is not reported as one.
            throw PortalAnswerException::unreadable('search');
        }

        $rows = [];

        foreach ($acts as $act) {
            if (is_array($act)) {
                $rows[] = array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $act);
            }
        }

        /** @var list<array<string, string|null>> $rows */
        return $rows;
    }

    /** @param  array<string, string|null>  $row */
    private function act(array $row): PortalAct
    {
        // The issuer and the publication, where the newer layout puts them beside the name rather than
        // in its paragraph: put back into the one line the SOAP service gives, and read like it.
        $title = PortalActTitle::line($row['title'] ?? '', $row['issuer'] ?? null, $row['published'] ?? null);
        $heading = preg_match($this->rule('heading_pattern'), $title, $match) === 1 ? $match : [];

        return PortalAct::fromTitle(
            typeLabel: trim($heading['type'] ?? ''),
            number: $this->number(trim($heading['number'] ?? '')),
            title: $title,
            issuer: null,
            officialJournal: $this->journal((new PortalActTitle($title))->journal()),
            inForceFrom: RomanianDateParser::parse($row['in_force'] ?? ''),
            link: $row['link'] ?? '',
        );
    }

    /**
     * The act's number as the SOAP service writes it — "1071" — from the title's "1.071", whose dot only
     * groups its thousands. Anything that is not digits grouped in threes is kept as the title writes it.
     */
    private function number(string $printed): string
    {
        $separator = $this->rule('number_group_separator');

        return preg_match('/\A\d{1,3}(?:' . preg_quote($separator, '/') . '\d{3})+\z/', $printed) === 1
            ? str_replace($separator, '', $printed)
            : $printed;
    }

    /**
     * The journal under the name the SOAP service gives it, when the playbook's list knows the name the
     * page printed; null otherwise, which keeps the page's own.
     */
    private function journal(?string $printed): ?string
    {
        if ($printed === null) {
            return null;
        }

        $known = $this->config->get($this->playbookKey() . '.search.journals');
        $name = is_array($known) ? ($known[mb_strtoupper($printed)] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    /**
     * Closes the search's browser. A failure here is the worker's to clean up at its own expiry, and
     * never the thing that hides why the search itself stopped.
     */
    private function close(string $sessionId): void
    {
        try {
            $this->worker->closeSession($sessionId);
        } catch (BrowserWorkerUnavailableException|BrowserWorkerAtCapacityException|BrowserRunFailedException|BrowserActionNotBuiltException) {
            // Already gone, or the worker went away: either way it is closed, or will be.
        }
    }

    /** @return array<string, mixed> */
    private function playbook(): array
    {
        $playbook = $this->config->get($this->playbookKey() . '.playbook');

        if (! is_array($playbook) || $playbook === []) {
            throw new RuntimeException('The legislation portal has no browser playbook.');
        }

        /** @var array<string, mixed> $playbook */
        return $playbook;
    }

    private function rule(string $name): string
    {
        return $this->config->string($this->playbookKey() . '.search.' . $name);
    }

    private function playbookKey(): string
    {
        return $this->config->string('sources.entries.' . self::SOURCE . '.playbook');
    }
}
