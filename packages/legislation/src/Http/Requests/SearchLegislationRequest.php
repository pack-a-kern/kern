<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Requests;

use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Kern\Legislation\Http\Requests\Concerns\FiltersLaws;
use Kern\Legislation\Retrieval\Query\HybridSearchQuery;
use Kern\Legislation\Retrieval\Query\SearchSettings;

/** A search of the laws' text: the words, the date it is as of, and the laws screen's filter. */
final class SearchLegislationRequest extends FormRequest
{
    use FiltersLaws;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->lawFilterRules(),
            'q' => ['required', 'string', 'min:2', 'max:500'],
            // Defaults to today. Explicit rather than implied: which date a search is "as of" decides which
            // versions can match, and a silent `now()` would make the same query return different law
            // tomorrow with nothing recording why.
            'as_of' => ['sometimes', 'date'],
            'source' => ['sometimes', 'string', 'max:32'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . app(SearchSettings::class)->maxResults],
        ];
    }

    public function searchQuery(): HybridSearchQuery
    {
        return new HybridSearchQuery(
            queryText: $this->string('q')->toString(),
            asOf: $this->asOf(),
            sourceCodes: $this->filled('source') ? [$this->string('source')->toString()] : null,
            laws: $this->lawFilter(),
            limit: $this->filled('limit') ? $this->integer('limit') : null,
        );
    }

    public function asOf(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->filled('as_of') ? $this->string('as_of')->toString() : 'today');
    }
}
