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
use RuntimeException;

/**
 * Searches legislatie.just.ro on its own search page, in the browser worker — the other way
 * {@see PortalSearch} can ask, for when the SOAP service is not answering.
 *
 * The site's results page carries the same title line the service's `Titlu` does, so an act is built
 * the same way ({@see PortalAct::fromTitle()}) and nothing after the search can tell which way it came.
 * Every fact about the page — its address, its parameters, its selectors, how a title begins, the code
 * that asks for fifty results a page — is in `config/playbooks/legislatie.php`; this reads what the
 * playbook extracted.
 *
 * A browser for each page, closed on the way out: a page is read on its own, by a queued job or in a
 * loop, so one that fails is asked again on its own and never strands a browser left open between them.
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

    /**
     * One results page, as the acts the playbook extracted — none when the page listed none.
     *
     * Asked again when refused — the same page, the same way, never another: the site answers about four
     * in ten of its searches with its error page, the same address answering with its results moments
     * later (2026-11-07, 2026-11-09). Still refused after the retries, the page is thrown as refused, never
     * read as an empty one. The error page means nothing about the search: one with no result, and a page
     * past the last, are answered with an empty list — and with the error page at random, like any other.
     */
    public function page(PortalQuery $query, int $page): PortalPage
    {
        $url = $this->rule('url') . '?' . http_build_query($this->parameters($query, $page), '', '&', PHP_QUERY_RFC1738);
        $retries = $this->config->integer('sources.entries.' . self::SOURCE . '.search_refused_retries');
        $pause = $this->config->integer('sources.entries.' . self::SOURCE . '.search_refused_pause_ms');

        $answer = $this->ask($url);

        for ($try = 0; ($answer['outcome'] ?? null) === 'refused' && $try < $retries; $try++) {
            Sleep::for($pause)->milliseconds();
            $answer = $this->ask($url);
        }

        $rows = match ($answer['outcome'] ?? null) {
            'found' => $this->rows($answer),
            'none' => [],
            'refused' => throw PortalSearchRefusedException::create(),
            default => throw PortalAnswerException::unreadable('search'),
        };

        return new PortalPage(
            array_map($this->act(...), $rows),
            count($rows) >= $this->config->integer('sources.entries.' . self::SOURCE . '.search_page_size'),
        );
    }

    /**
     * The address's query for one page: the act's number in the site's own "numar-an" form, or the
     * title's words with the year as the signing-date range at both ends; fifty a page, by the site's
     * code for it; and the page number from the page the site starts numbering on.
     *
     * @return array<string, string>
     */
    private function parameters(PortalQuery $query, int $page): array
    {
        $parameters = $query->number !== null && $query->year !== null
            ? [$this->rule('number_parameter') => strtr($this->rule('number_format'), ['{number}' => (string) $query->number, '{year}' => (string) $query->year])]
            : [$this->rule('title_parameter') => (string) $query->words];

        if (! $query->isByNumber() && $query->year !== null) {
            $parameters[$this->rule('year_from_parameter')] = (string) $query->year;
            $parameters[$this->rule('year_to_parameter')] = (string) $query->year;
        }

        $parameters[$this->rule('page_size_parameter')] = $this->rule('page_size_value');

        if ($page >= $this->config->integer($this->playbookKey() . '.search.page_parameter_from')) {
            $parameters[$this->rule('page_parameter')] = (string) $page;
        }

        return $parameters;
    }

    /**
     * The playbook's `search` sequence at this address, in a browser of its own.
     *
     * @return array<string, mixed>
     */
    private function ask(string $url): array
    {
        return PortalBrowserException::translate(fn (): array => $this->worker->run($this->playbook(), self::SEQUENCE, ['url' => $url]));
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
