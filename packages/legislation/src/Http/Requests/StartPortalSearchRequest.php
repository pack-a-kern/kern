<?php

declare(strict_types=1);

namespace Kern\Legislation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kern\Legislation\Legislatie\ImportableTypes;
use Kern\Legislation\Legislatie\PortalQuery;

/**
 * What a portal search may ask for: words of a title (and a year, if wanted), or a number and its year —
 * never both; and one of the types the ingest task takes, if a type. The year runs from
 * `legislation.form.min_year` to this one.
 */
final class StartPortalSearchRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'required_without:number', 'prohibits:number', 'string', 'min:3', 'max:200'],
            'number' => ['nullable', 'required_without:title', 'integer', 'min:1', 'max:99999'],
            'year' => ['nullable', 'required_with:number', 'integer', 'min:' . config()->integer('legislation.form.min_year'), 'max:' . date('Y')],
            'type' => ['nullable', 'string', Rule::in(app(ImportableTypes::class)->keys())],
        ];
    }

    public function portalQuery(): PortalQuery
    {
        $year = $this->filled('year') ? $this->integer('year') : null;
        $type = $this->filled('type') ? $this->string('type')->toString() : null;

        return $this->filled('number') && $year !== null
            ? PortalQuery::byNumber($this->integer('number'), $year, $type)
            : PortalQuery::byTitle($this->string('title')->toString(), $year, $type);
    }
}
