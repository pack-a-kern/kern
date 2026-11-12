<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Kern\Legislation\Http\Requests\Concerns\FiltersLaws;
use Kern\Platform\Routing\PageSizes;

/** The laws list's question: the screen's filter, and which page of what it keeps — of a size every list allows. */
final class ListLawsRequest extends FormRequest
{
    use FiltersLaws;

    /** @return array<string, list<mixed>> */
    public function rules(PageSizes $sizes): array
    {
        return [
            ...$this->lawFilterRules(),
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => $sizes->rule(),
        ];
    }

    /** @return positive-int */
    public function page(): int
    {
        return $this->filled('page') ? max(1, $this->integer('page')) : 1;
    }
}
