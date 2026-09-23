<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\FiscalParameters\ReadFiscalParametersTest;

use App\Domain\TaxKnowledge\Enums\OutlineLevel;
use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\FiscalParameters\Actions\ReadFiscalParameters;
use App\Domain\TaxKnowledge\FiscalParameters\Actions\StartFiscalYear;
use App\Domain\TaxKnowledge\FiscalParameters\FiscalParameterDecisionException;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawMatch;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawProvisions;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\Provision;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\Reading;
use App\Domain\TaxKnowledge\FiscalParameters\Recording\CandidateRecorder;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceOutlineNode;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Parsing\SectionText;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;
use App\Domain\TaxKnowledge\Services\BuildSectionHistory;
use DateTimeImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The fiscal values read end to end: the minimum wage from its decisions, every other value from the
 * law itself, each proposed as a candidate with the paragraph it was read in.
 *
 * The law's paragraphs are the Codul fiscal's own words (the 2026-08-08 consolidation, as read in);
 * the decisions' sentence is HG 1447/2022's, HG 900/2023's and HG 1506/2024's. The model is faked:
 * what it answers is what a reading believes until the law itself contradicts it, so a test says
 * what was answered and asserts what became of it.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

/** A version of a law or decision in force from `$from`, with its headings and provisions. */
function lawVersion(string $identifier, string $type, string $title, string $from, ?string $until = null): SourceDocumentVersion
{
    $document = SourceDocument::query()->create([
        'official_source_id' => app(OfficialSourceRepository::class)->idForCode('LEGISLATIE'),
        'document_type' => $type,
        'external_identifier' => $identifier,
        'canonical_url' => 'https://legislatie.just.ro/Public/DetaliiDocument/' . random_int(1, 999999),
        'title' => $title,
    ]);

    return SourceDocumentVersion::factory()->create([
        'source_document_id' => $document->id,
        'effective_from' => $from,
        'effective_until' => $until,
        'consolidation_ref' => (string) random_int(1, 999999),
    ]);
}

/** @param list<array{string, string}> $paragraphs citation => text, each under its own article */
function heading(SourceDocumentVersion $version, string $path, array $paragraphs): void
{
    $node = SourceOutlineNode::query()->create([
        'document_version_id' => $version->id,
        'level' => OutlineLevel::CAPITOL->value,
        'label' => $path,
        'name' => '',
        'path' => $path,
        'ordinal' => 1,
        'position' => SourceOutlineNode::query()->where('document_version_id', $version->id)->count(),
    ]);

    $articles = [];

    foreach ($paragraphs as $index => [$citation, $text]) {
        $label = Str::before($citation, ' alin.');
        $articles[$label] ??= SourceSection::factory()->create([
            'document_version_id' => $version->id,
            'outline_id' => $node->id,
            'citation_label' => $label,
            'ordinal' => SourceSection::query()->where('document_version_id', $version->id)->count() + 1,
            'text' => '',
        ]);
        SourceSection::factory()->create([
            'document_version_id' => $version->id,
            'parent_id' => $articles[$label]->id,
            'section_type' => SectionType::ALINEAT,
            'citation_label' => $citation,
            'ordinal' => $index + 1,
            'text' => $text,
        ]);
    }
}

function codulFiscal(): void
{
    $version = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2023-01-01');

    heading($version, 'Titlul IV Capitolul I', [
        ['Art. 64 alin. (1)', 'Cota de impozit este de 10% și se aplică asupra venitului impozabil corespunzător fiecărei surse din fiecare categorie'],
    ]);
    heading($version, 'Titlul IV Capitolul II', [
        ['Art. 72 alin. (2)', 'Venitul net din drepturile de proprietate intelectuală se determină prin deducerea din venitul brut a cheltuielilor determinate prin aplicarea cotei de 40% asupra venitului brut, iar pentru drepturile conexe prin aplicarea cotei de 25% asupra venitului brut.'],
    ]);
    heading($version, 'Titlul IV Capitolul IV', [
        ['Art. 84 alin. (3)', 'Venitul net anual din cedarea folosinței bunurilor se stabilește prin deducerea din venitul brut a cheltuielilor determinate prin aplicarea cotei de 20% asupra venitului brut.'],
    ]);
    heading($version, 'Titlul IV Capitolul VI', [
        ['Art. 100 alin. (1)', 'Venitul impozabil lunar din pensii se stabilește prin deducerea din venitul din pensie a sumei neimpozabile lunare de 3.000 lei.'],
    ]);
    // Another chapter of the same title applies a quota of its own, to something else entirely.
    heading($version, 'Titlul IV Capitolul VIII', [
        ['Art. 110 alin. (3)', 'Veniturile din jocuri de noroc se impun prin aplicarea cotei de 40% asupra venitului net care depășește 66.750 lei.'],
    ]);
}

function minimumWageDecision(string $identifier, string $title, string $inForce, string $sentence): void
{
    $version = lawVersion($identifier, 'HOTARARE', $title, $inForce);

    SourceSection::factory()->create(['document_version_id' => $version->id, 'citation_label' => 'Art. 1', 'ordinal' => 1, 'text' => $sentence]);
}

function decisions(): void
{
    minimumWageDecision(
        'HOTARARE 1447/2022',
        'HOTĂRÂRE nr. 1.447 din 8 decembrie 2022 pentru stabilirea salariului de bază minim brut pe ţară garantat în plată',
        '2022-12-09',
        'Începând cu data de 1 ianuarie 2023, salariul de bază minim brut pe țară garantat în plată, prevăzut la art. 164 alin. (1) din Legea nr. 53/2003 - Codul muncii, republicată, cu modificările și completările ulterioare, se stabilește în bani, fără a include sporuri și alte adaosuri, la suma de 3.000 lei lunar, pentru un program normal de lucru în medie de 165,333 ore pe lună.'
    );
    minimumWageDecision(
        'HOTARARE 900/2023',
        'HOTĂRÂRE nr. 900 din 28 septembrie 2023 pentru stabilirea salariului de bază minim brut pe ţară garantat în plată',
        '2023-09-28',
        'Începând cu data de 1 octombrie 2023, salariul de bază minim brut pe țară garantat în plată, prevăzut la art. 164 alin. (1) din Legea nr. 53/2003 - Codul muncii, republicată, cu modificările și completările ulterioare, se stabilește în bani, fără a include sporuri și alte adaosuri, la suma de 3.300 lei lunar, pentru un program normal de lucru în medie de 165,333 ore pe lună.'
    );
    minimumWageDecision(
        'HOTARARE 1506/2024',
        'HOTĂRÂRE nr. 1.506 din 27 noiembrie 2024 pentru stabilirea salariului de bază minim brut pe ţară garantat în plată',
        '2025-01-01',
        'Începând cu data de 1 ianuarie 2025, salariul de bază minim brut pe țară garantat în plată se stabilește în bani, fără a include sporuri și alte adaosuri, la suma de 4.050 lei lunar, pentru un program normal de lucru în medie de 165,334 ore pe lună.'
    );
}

/**
 * The models, as a reading talks to them: an answer per question they are asked, matched by a word
 * of the question, and "nothing here" for every other question.
 *
 * @param  array<string, array<string, mixed>>  $answers  a word of the question => what the reader answers
 * @param  array<string, array<string, mixed>>  $second  what the second reader answers, when one reads too
 * @param  array<string, array<string, mixed>>  $together  what a tax's own call answers, by the code of each value
 */
function readers(array $answers, array $second = [], array $together = []): void
{
    config([
        'ai.providers.env_fallback.enabled' => true,
        'ai.providers.env_fallback.provider' => 'custom',
        'ai.providers.env_fallback.base_url' => 'https://ai.test',
        'ai.providers.env_fallback.model' => 'reader-1',
        'ai.legislation.reader' => 'reader-1',
        'ai.legislation.second_reader' => $second === [] ? '' : 'reader-2',
        'ai.legislation.from_year' => 2023,
    ]);

    Http::fake(['ai.test/*' => static function (HttpRequest $request) use ($answers, $second, $together) {
        $sent = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // A tax's questions in one call are answered by the code of each.
        if ($together !== [] && str_contains($sent, 'QUESTIONS')) {
            return Http::response(['choices' => [['message' => ['content' => json_encode($together, JSON_THROW_ON_ERROR)]]]]);
        }

        // Matched against the question alone: the paragraphs travel in the same request, and a key
        // looked for in all of it would be answered by whatever the law happens to mention.
        $asked = Str::between($sent, 'QUESTION:', 'The value asked for is');
        $model = $request->data()['model'] ?? '';

        foreach ($model === 'reader-2' ? $second : $answers as $word => $answer) {
            if (str_contains($asked, $word)) {
                return Http::response(['choices' => [['message' => ['content' => json_encode($answer, JSON_THROW_ON_ERROR)]]]]);
            }
        }

        return Http::response(['choices' => [['message' => ['content' => '{"value": null}']]]]);
    }]);
}

/**
 * What each reader answers for the law the fixture holds: the paragraph, the number, the sentence.
 *
 * @return array<string, array<string, mixed>>
 */
function read2023(): array
{
    return [
        'cota de impozit pe venit' => ['value' => 10, 'citation' => 'Art. 64 alin. (1)', 'quote' => 'Cota de impozit este de 10%'],
        'drepturi de proprietate intelectuală' => ['value' => 40, 'citation' => 'Art. 72 alin. (2)', 'quote' => 'aplicarea cotei de 40% asupra venitului brut'],
        'cedarea folosinței bunurilor' => ['value' => 20, 'citation' => 'Art. 84 alin. (3)', 'quote' => 'aplicarea cotei de 20% asupra venitului brut'],
        'venitul din pensii' => ['value' => 3000, 'citation' => 'Art. 100 alin. (1)', 'quote' => 'deducerea din venitul din pensie a sumei neimpozabile lunare de 3.000 lei'],
    ];
}

/** @return array<int, array<int, mixed>> code, from, until, value, source citation */
function candidates(): array
{
    return FiscalParameterVersion::query()
        ->join('fiscal_parameters', 'fiscal_parameters.id', '=', 'fiscal_parameter_versions.fiscal_parameter_id')
        ->orderBy('fiscal_parameters.code')
        ->orderBy('valid_from')
        ->get(['fiscal_parameter_versions.*', 'fiscal_parameters.code'])
        ->map(static fn (FiscalParameterVersion $version): array => [
            $version->getAttribute('code'),
            $version->valid_from->format('Y-m-d'),
            $version->valid_until?->format('Y-m-d'),
            $version->value_json,
            SourceSection::query()->whereKey($version->source_section_id)->value('citation_label'),
        ])->values()->all();
}

it('reads every value from the law of its year, and proposes it on the paragraph quoted', function (): void {
    $this->travelTo('2023-06-01');
    codulFiscal();
    decisions();
    readers(read2023());

    app(ReadFiscalParameters::class)();
    app(StartFiscalYear::class)(2023);

    expect(array_values(array_filter(candidates(), static fn (array $row): bool => $row[1] === '2023-01-01')))->toBe([
        ['RO.INCOME_TAX.IP_EXPENSE_QUOTA', '2023-01-01', '2023-12-31', ['value' => '0.40'], 'Art. 72 alin. (2)'],
        ['RO.INCOME_TAX.PENSION_TAX_FREE', '2023-01-01', '2023-12-31', ['amount_minor' => 300000, 'currency' => 'RON'], 'Art. 100 alin. (1)'],
        ['RO.INCOME_TAX.RATE', '2023-01-01', '2023-12-31', ['value' => '0.10'], 'Art. 64 alin. (1)'],
        ['RO.INCOME_TAX.RENT_EXPENSE_QUOTA', '2023-01-01', '2023-12-31', ['value' => '0.20'], 'Art. 84 alin. (3)'],
        ['RO.MIN_WAGE', '2023-01-01', '2023-09-30', ['amount_minor' => 300000, 'currency' => 'RON'], 'Art. 1'],
    ]);

    // What was read is kept beside the value: the paragraph, the sentence, and which model read it.
    $incomeTax = FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.INCOME_TAX.RATE')->select('id'))
        ->where('valid_from', '2023-01-01')->sole();

    expect($incomeTax->evidence['readings'][0] ?? [])->toEqual([
        'kind' => 'law',
        'model' => 'reader-1',
        'citation' => 'Art. 64 alin. (1)',
        'quote' => 'Cota de impozit este de 10%',
    ])
        // Every value is only proposed; none counts yet.
        ->and(FiscalParameterVersion::query()->where('status', '<>', RuleApprovalStatus::CANDIDATE->value)->count())->toBe(0);
});

it('records nothing from an answer the law does not hold', function (): void {
    $this->travelTo('2023-06-01');
    codulFiscal();
    decisions();
    readers([
        // A sentence no paragraph holds, a paragraph that says another number, and a paragraph that
        // is not in the text the reader was given.
        'cota de impozit pe venit' => ['value' => 16, 'citation' => 'Art. 64 alin. (1)', 'quote' => 'Cota de impozit este de 16%'],
        'drepturi de proprietate intelectuală' => ['value' => 25, 'citation' => 'Art. 72 alin. (2)', 'quote' => 'aplicarea cotei de 40% asupra venitului brut'],
        'cedarea folosinței bunurilor' => ['value' => 20, 'citation' => 'Art. 999', 'quote' => 'aplicarea cotei de 20% asupra venitului brut'],
    ]);

    app(ReadFiscalParameters::class)();

    expect(fn () => app(StartFiscalYear::class)(2023))->toThrow(FiscalParameterDecisionException::class)
        ->and(array_values(array_unique(array_map(static fn (mixed $code): string => is_string($code) ? $code : '', array_column(candidates(), 0)))))->toBe(['RO.MIN_WAGE']);
});

it('proposes the first reader\'s value and says so when the second reads something else', function (): void {
    $this->travelTo('2023-06-01');
    codulFiscal();
    decisions();
    readers(read2023(), [
        'cota de impozit pe venit' => ['value' => 10, 'citation' => 'Art. 64 alin. (1)', 'quote' => 'Cota de impozit este de 10%'],
        // The second reader reads the same paragraph and takes the other quota in it.
        'drepturi de proprietate intelectuală' => [
            'value' => 25,
            'citation' => 'Art. 72 alin. (2)',
            'quote' => 'pentru drepturile conexe prin aplicarea cotei de 25% asupra venitului brut',
        ],
    ]);

    app(StartFiscalYear::class)(2023);

    $checks = static fn (string $code): array => array_column(FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', $code)->select('id'))
        ->where('valid_from', '2023-01-01')->sole()->evidence['checks'] ?? [], 'kind');

    expect($checks('RO.INCOME_TAX.RATE'))->toBe([])
        ->and($checks('RO.INCOME_TAX.IP_EXPENSE_QUOTA'))->toBe(['readers_disagree']);
});

it('asks nothing again for a year already read, and never proposes a rejected value twice', function (): void {
    $this->travelTo('2023-06-01');
    codulFiscal();
    decisions();
    readers(read2023());

    app(ReadFiscalParameters::class)();
    app(StartFiscalYear::class)(2023);
    $first = FiscalParameterVersion::query()->pluck('id')->sort()->values()->all();

    $rejected = FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.INCOME_TAX.RENT_EXPENSE_QUOTA')->select('id'))
        ->where('valid_from', '2023-01-01')->sole();
    $rejected->forceFill(['status' => RuleApprovalStatus::REJECTED->value])->save();
    readers(read2023());

    expect(fn () => app(StartFiscalYear::class)(2023))->toThrow(FiscalParameterDecisionException::class);

    // Nothing was asked about a value already held on a paragraph that still stands — neither the
    // one a person rejected, nor the ones waiting to be approved.
    Http::assertNotSent(static fn (HttpRequest $request): bool => str_contains(
        json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'cota de impozit pe venit',
    ));
    expect(FiscalParameterVersion::query()->pluck('id')->sort()->values()->all())->toBe($first)
        ->and($rejected->fresh()?->status)->toBe(RuleApprovalStatus::REJECTED);
});

it('reads the decisions without asking the law', function (): void {
    codulFiscal();
    decisions();
    readers(read2023());

    app(ReadFiscalParameters::class)();

    Http::assertNothingSent();
    expect(array_values(array_unique(array_map(static fn (mixed $code): string => is_string($code) ? $code : '', array_column(candidates(), 0)))))->toBe(['RO.MIN_WAGE']);
});

it('reads one year on its own, and refuses a year its law says nothing for', function (): void {
    $this->travelTo('2023-06-01');
    codulFiscal();
    readers(read2023());

    expect(app(StartFiscalYear::class)(2024))->toBe(4)
        ->and(array_column(array_values(candidates()), 1))->each->toBe('2024-01-01');

    // Before the law was read in there is no text to read, and nothing is proposed.
    expect(fn () => app(StartFiscalYear::class)(2016))->toThrow(FiscalParameterDecisionException::class);
});

it('reads a wording the portal\'s note dates from before the next consolidation', function (): void {
    // No consolidation between 2016 and 2021, as the portal lists the Codul fiscal's.
    $old = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2016-12-06', '2020-12-31');
    heading($old, 'Titlul IV Capitolul I', [['Art. 64 alin. (1)', 'Cota de impozit este de 16% și se aplică asupra venitului impozabil']]);
    $new = SourceDocumentVersion::factory()->create(['source_document_id' => $old->source_document_id, 'effective_from' => '2021-01-01', 'effective_until' => '2022-12-31', 'consolidation_ref' => '2021']);
    $noted = "Cota de impozit este de 10% și se aplică asupra venitului impozabil\n(la 01-01-2018, Partea introductivă a alineatului (1) din Articolul 64 a fost modificată de Punctul 15, Articolul I din ORDONANȚA DE URGENȚĂ nr. 79 din 8 noiembrie 2017)";
    heading($new, 'Titlul IV Capitolul I', [['Art. 64 alin. (1)', $noted]]);
    // A later consolidation with the same wording has it itself: nothing to date by the note.
    $later = SourceDocumentVersion::factory()->create(['source_document_id' => $old->source_document_id, 'effective_from' => '2023-01-01', 'consolidation_ref' => '2023']);
    heading($later, 'Titlul IV Capitolul I', [['Art. 64 alin. (1)', $noted]]);

    foreach (SourceSection::query()->get() as $section) {
        $section->forceFill(['normative_hash' => SectionText::fingerprint($section->text), 'amended_on' => SectionText::amendedOn($section->text)])->save();
    }

    app(BuildSectionHistory::class)($old->source_document_id);

    $law = app(LawProvisions::class);
    $read = static fn (string $day): array => array_map(
        static fn (Provision $provision): array => [$provision->documentVersionId, $provision->datedByNote, str_contains($provision->text, '10%')],
        array_values(array_filter($law->inForce('Titlul IV Capitolul I', new DateTimeImmutable($day)), static fn (Provision $provision): bool => $provision->citation === 'Art. 64 alin. (1)')),
    );

    expect($read('2018-01-01'))->toBe([[$new->id, '2018-01-01', true]])
        ->and($read('2017-06-01'))->toBe([[$old->id, null, false]])
        ->and($read('2023-06-01'))->toBe([[$later->id, null, true]]);
});

it('keeps a rule to the paragraph it was approved on for another year, and says when it moved', function (): void {
    codulFiscal();
    $section = static fn (string $citation): SourceSection => SourceSection::query()->where('citation_label', $citation)->sole();
    $quota = static fn (int $year): Reading => new Reading(
        'RO.INCOME_TAX.RENT_EXPENSE_QUOTA',
        new DateTimeImmutable("{$year}-01-01"),
        new DateTimeImmutable("{$year}-12-31"),
        ['value' => '0.20'],
        [20],
        ['kind' => 'law'],
    );
    $found = static fn (string $citation): array => [new LawMatch($section($citation)->id, $citation, $section($citation)->document_version_id, [20])];
    $parameter = FiscalParameter::query()->create(['code' => 'RO.INCOME_TAX.RENT_EXPENSE_QUOTA', 'value_type' => 'DECIMAL']);
    insertApprovedFiscalParameterVersion($parameter->id, $section('Art. 84 alin. (3)')->id, ['valid_from' => '2023-01-01', 'valid_until' => '2023-12-31', 'value_json' => json_encode(['value' => '0.20'], JSON_THROW_ON_ERROR)]);

    app(CandidateRecorder::class)->record([['reading' => $quota(2024), 'matches' => $found('Art. 84 alin. (3)')]], []);
    app(CandidateRecorder::class)->record([['reading' => $quota(2025), 'matches' => $found('Art. 72 alin. (2)')]], []);

    $candidate = static fn (string $from): FiscalParameterVersion => FiscalParameterVersion::query()->where('valid_from', $from)->sole();

    expect($candidate('2024-01-01')->evidence['checks'] ?? [])->toBe([])
        ->and($candidate('2025-01-01')->source_section_id)->toBe($section('Art. 72 alin. (2)')->id)
        ->and($candidate('2025-01-01')->evidence['checks'] ?? [])->toEqual([['kind' => 'paragraph_changed', 'citation' => 'Art. 84 alin. (3)', 'now' => 'Art. 72 alin. (2)', 'year' => 2025]]);
});

it('takes a quote across the points of one paragraph, and a number the law writes with a separator', function (): void {
    $this->travelTo('2023-06-01');
    $version = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2023-01-01');
    heading($version, 'Titlul IV Capitolul IV', [
        ['Art. 84 alin. (3)', 'Venitul net anual din cedarea folosinței bunurilor se stabilește astfel:'],
    ]);
    heading($version, 'Titlul IV Capitolul VI', [
        ['Art. 100 alin. (1)', 'Venitul impozabil lunar din pensii se stabilește prin deducerea din venitul din pensie a sumei neimpozabile lunare de 3.000 lei.'],
    ]);
    $paragraph = SourceSection::query()->where('citation_label', 'Art. 84 alin. (3)')->sole();
    SourceSection::factory()->create([
        'document_version_id' => $version->id,
        'parent_id' => $paragraph->id,
        'section_type' => SectionType::LITERA,
        'citation_label' => 'Art. 84 alin. (3) lit. a)',
        'ordinal' => SourceSection::query()->where('document_version_id', $version->id)->count() + 1,
        'text' => 'prin aplicarea cotei de 20% asupra venitului brut;',
    ]);

    readers([
        // The rule is written across the paragraph and the point below it, and the reader quotes it
        // as one — labels and all, which is how the paragraphs were given to it.
        'cedarea folosinței bunurilor' => [
            'value' => 20,
            'citation' => 'Art. 84 alin. (3)',
            'quote' => 'Art. 84 alin. (3) Venitul net anual din cedarea folosinței bunurilor se stabilește astfel: Art. 84 alin. (3) lit. a) prin aplicarea cotei de 20% asupra venitului brut;',
        ],
        'suma neimpozabilă lunară' => [
            'value' => 3000,
            'citation' => 'Art. 100 alin. (1)',
            'quote' => 'deducerea din venitul din pensie a sumei neimpozabile lunare de 3.000 lei',
        ],
    ]);

    app(StartFiscalYear::class)(2023);

    $value = static fn (string $code): array => FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', $code)->select('id'))->sole()->value_json;

    expect($value('RO.INCOME_TAX.RENT_EXPENSE_QUOTA'))->toBe(['value' => '0.20'])
        ->and($value('RO.INCOME_TAX.PENSION_TAX_FREE'))->toBe(['amount_minor' => 300000, 'currency' => 'RON']);
});

it('reads a rule where it was approved, so a year whose paragraph no longer says it proposes nothing', function (): void {
    $this->travelTo('2024-06-01');
    bootstrapIdentityCryptoForTests();
    $version = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2024-01-01');
    heading($version, 'Titlul IV Capitolul IV', [
        // The paragraph the rule rested on, rewritten: it no longer sets a quota of expenses.
        ['Art. 84 alin. (3)', 'Venitul net anual din cedarea folosinței bunurilor se stabilește pe baza datelor din contabilitate.'],
    ]);
    heading($version, 'Titlul IV Capitolul VIII', [
        // Another article of the same title, applying a quota of its own.
        ['Art. 110 alin. (3)', 'Veniturile din jocuri de noroc se impun prin aplicarea cotei de 20% asupra venitului net.'],
    ]);
    $approvedOn = SourceSection::query()->where('citation_label', 'Art. 84 alin. (3)')->sole();
    $parameter = FiscalParameter::query()->create(['code' => 'RO.INCOME_TAX.RENT_EXPENSE_QUOTA', 'value_type' => 'DECIMAL']);
    insertApprovedFiscalParameterVersion($parameter->id, $approvedOn->id, [
        'valid_from' => '2022-01-01',
        'valid_until' => '2022-12-31',
        'value_json' => json_encode(['value' => '0.20'], JSON_THROW_ON_ERROR),
    ]);

    // Whatever it is asked, the reader answers from the paragraph it is given.
    readers(['cedarea folosinței bunurilor' => [
        'value' => 20,
        'citation' => 'Art. 110 alin. (3)',
        'quote' => 'aplicarea cotei de 20% asupra venitului net',
    ]]);

    // The rule rests on Art. 84 alin. (3), which no longer says it: nothing is proposed for 2024,
    // though another article of the title holds a quota like the one it used to have — and with
    // nothing else in this law to read, the year proposes nothing at all.
    expect(fn () => app(StartFiscalYear::class)(2024))->toThrow(FiscalParameterDecisionException::class);

    expect(FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.INCOME_TAX.RENT_EXPENSE_QUOTA')->select('id'))
        ->where('valid_from', '2024-01-01')->count())->toBe(0);
});

it('reads a note-dated wording with its own points, and none of those it replaced', function (): void {
    // No consolidation between 2016 and 2021, as the portal lists the Codul fiscal's.
    $old = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2016-12-06', '2020-12-31');
    heading($old, 'Titlul IV Capitolul VI', [['Art. 100 alin. (1)', 'Venitul impozabil lunar din pensii se stabilește prin deducerea următoarelor:']]);
    $new = SourceDocumentVersion::factory()->create(['source_document_id' => $old->source_document_id, 'effective_from' => '2021-01-01', 'consolidation_ref' => '2021']);
    heading($new, 'Titlul IV Capitolul VI', [['Art. 100 alin. (1)', "Venitul impozabil lunar din pensii se stabilește prin deducerea din venitul din pensie a sumei neimpozabile lunare de 2.000 lei.\n(la 01-01-2018, Alineatul (1) din Articolul 100 a fost modificat de Punctul 20, Articolul I din ORDONANȚA DE URGENȚĂ nr. 79 din 8 noiembrie 2017)"]]);

    // What stood under the paragraph in the consolidation that has no 2018 wording of it.
    $point = static function (SourceDocumentVersion $version, string $text): void {
        $paragraph = SourceSection::query()->where('document_version_id', $version->id)->where('citation_label', 'Art. 100 alin. (1)')->sole();
        SourceSection::factory()->create([
            'document_version_id' => $version->id,
            'parent_id' => $paragraph->id,
            'section_type' => SectionType::LITERA,
            'citation_label' => 'Art. 100 alin. (1) lit. b)',
            'ordinal' => SourceSection::query()->where('document_version_id', $version->id)->count() + 1,
            'text' => $text,
        ]);
    };
    $point($old, 'suma neimpozabilă lunară de 1.050 lei.');

    foreach (SourceSection::query()->get() as $section) {
        $section->forceFill(['normative_hash' => SectionText::fingerprint($section->text), 'amended_on' => SectionText::amendedOn($section->text)])->save();
    }

    app(BuildSectionHistory::class)($old->source_document_id);

    $read = array_map(
        static fn (Provision $provision): string => $provision->citation . ' ' . trim((string) preg_replace('/\s+/u', ' ', $provision->text)),
        app(LawProvisions::class)->inForce('Titlul IV Capitolul VI', new DateTimeImmutable('2018-06-01')),
    );

    // The 2018 wording of the paragraph, and not the 1.050 lei that stood under the one it replaced.
    expect(implode(' | ', $read))->toContain('2.000 lei')->not->toContain('1.050 lei');
});

it('reads a tax in one call, and asks again by itself only for what that left out', function (): void {
    $this->travelTo('2023-06-01');
    codulFiscal();
    $answers = read2023();

    // The tax answers three of its four questions, and says nothing of the rent quota.
    readers(['cedarea folosinței bunurilor' => $answers['cedarea folosinței bunurilor']], [], [
        'RO.INCOME_TAX.RATE' => $answers['cota de impozit pe venit'],
        'RO.INCOME_TAX.IP_EXPENSE_QUOTA' => $answers['drepturi de proprietate intelectuală'],
        'RO.INCOME_TAX.PENSION_TAX_FREE' => $answers['venitul din pensii'],
        'RO.INCOME_TAX.RENT_EXPENSE_QUOTA' => ['value' => null, 'citation' => null, 'quote' => null],
    ]);

    app(StartFiscalYear::class)(2023);

    $value = static fn (string $code): array => FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', $code)->select('id'))->sole()->value_json;

    // Every value of the tax is read, and two calls did it: one for the tax, one for what it left.
    expect($value('RO.INCOME_TAX.RATE'))->toBe(['value' => '0.10'])
        ->and($value('RO.INCOME_TAX.IP_EXPENSE_QUOTA'))->toBe(['value' => '0.40'])
        ->and($value('RO.INCOME_TAX.PENSION_TAX_FREE'))->toBe(['amount_minor' => 300000, 'currency' => 'RON'])
        ->and($value('RO.INCOME_TAX.RENT_EXPENSE_QUOTA'))->toBe(['value' => '0.20'])
        ->and(Http::recorded()->count())->toBe(2);
});

it('asks one question per call where a tax is not read in one', function (): void {
    $this->travelTo('2023-06-01');
    codulFiscal();
    config(['ai.legislation.one_call_per_tax' => false]);
    readers(read2023());

    app(StartFiscalYear::class)(2023);

    expect(Http::recorded()->count())->toBe(4)
        ->and(FiscalParameterVersion::query()->where('valid_from', '2023-01-01')->count())->toBe(4);
});
