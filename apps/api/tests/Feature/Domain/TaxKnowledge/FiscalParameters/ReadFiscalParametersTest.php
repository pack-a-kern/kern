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
    heading($version, 'Titlul V Capitolul II Secţiunea a 2-a', [
        ['Art. 138 alin. (1)', '25% datorată de către persoanele fizice care au calitatea de angajați sau pentru care există obligația plății contribuției de asigurări sociale, potrivit prezentei legi;'],
    ]);
    heading($version, 'Titlul V Capitolul II Secţiunea a 5-a', [
        ['Art. 148 alin. (2)', 'nivelul de 12 salarii minime brute pe țară, în cazul veniturilor realizate cuprinse între 12 salarii minime brute pe țară inclusiv și 24 de salarii minime brute pe țară;'],
    ]);
    heading($version, 'Titlul V Capitolul III Secţiunea a 2-a', [
        ['Art. 156 alin. (1)', 'Cota de contribuție de asigurări sociale de sănătate este de 10% și se datorează de către persoanele fizice care au calitatea de angajați'],
    ]);
    heading($version, 'Titlul V Capitolul III Secţiunea a 5-a', [
        ['Art. 170 alin. (3)', 'nivelul a 6 salarii minime brute pe țară, în cazul veniturilor realizate cuprinse între 6 salarii minime brute pe țară inclusiv și 12 salarii minime brute pe țară;'],
        ['Art. 170 alin. (4)', 'nivelul de 24 de salarii minime brute pe țară, în cazul veniturilor realizate cel puțin egale cu 24 de salarii minime brute pe țară.'],
    ]);
    // Chapter IX says "25% datorată de către persoanele fizice" too — and is not Chapter II.
    heading($version, 'Titlul V Capitolul IX', [
        ['Art. 220^3 alin. (1)', '25% datorată de către persoanele fizice pentru care se datorează contribuția asiguratorie pentru muncă'],
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
 */
function readers(array $answers, array $second = []): void
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

    Http::fake(['ai.test/*' => static function (HttpRequest $request) use ($answers, $second) {
        $asked = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
 * What each reader answers for the law the fixture holds: the paragraph, the numbers, the sentence.
 *
 * @return array<string, array<string, mixed>>
 */
function read2023(): array
{
    return [
        'cota de impozit pe venit' => ['value' => 10, 'citation' => 'Art. 64 alin. (1)', 'quote' => 'Cota de impozit este de 10%'],
        'sociale datorată de persoanele fizice' => ['value' => 25, 'citation' => 'Art. 138 alin. (1)', 'quote' => '25% datorată de către persoanele fizice'],
        'cota contribuției de asigurări sociale de sănătate' => ['value' => 10, 'citation' => 'Art. 156 alin. (1)', 'quote' => 'Cota de contribuție de asigurări sociale de sănătate este de 10%'],
        'contribuției de asigurări sociale pentru veniturile din activități independente' => [
            'values' => [12, 24],
            'citation' => 'Art. 148 alin. (2)',
            'quote' => 'nivelul de 12 salarii minime brute pe țară, în cazul veniturilor realizate cuprinse între 12 salarii minime brute pe țară inclusiv și 24 de salarii minime brute pe țară;',
        ],
        'art. 155 alin. (1) lit. c)-h)' => [
            'values' => [6, 12],
            'citation' => 'Art. 170 alin. (3)',
            'quote' => 'nivelul a 6 salarii minime brute pe țară, în cazul veniturilor realizate cuprinse între 6 salarii minime brute pe țară inclusiv și 12 salarii minime brute pe țară;',
        ],
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
        ['RO.CAS.BASE_WAGES', '2023-01-01', '2023-12-31', ['wages' => [12, 24]], 'Art. 148 alin. (2)'],
        ['RO.CAS.RATE', '2023-01-01', '2023-12-31', ['value' => '0.25'], 'Art. 138 alin. (1)'],
        ['RO.CASS.OTHER_INCOME.BANDS_WAGES', '2023-01-01', '2023-12-31', ['wages' => [6, 12]], 'Art. 170 alin. (3)'],
        ['RO.CASS.RATE', '2023-01-01', '2023-12-31', ['value' => '0.10'], 'Art. 156 alin. (1)'],
        ['RO.INCOME_TAX.RATE', '2023-01-01', '2023-12-31', ['value' => '0.10'], 'Art. 64 alin. (1)'],
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
        'cota contribuției de asigurări sociale de sănătate' => ['value' => 25, 'citation' => 'Art. 156 alin. (1)', 'quote' => 'Cota de contribuție de asigurări sociale de sănătate este de 10%'],
        'sociale datorată de persoanele fizice' => ['value' => 25, 'citation' => 'Art. 999', 'quote' => '25% datorată de către persoanele fizice'],
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
        'art. 155 alin. (1) lit. c)-h)' => [
            'values' => [24],
            'citation' => 'Art. 170 alin. (4)',
            'quote' => 'nivelul de 24 de salarii minime brute pe țară, în cazul veniturilor realizate cel puțin egale cu 24 de salarii minime brute pe țară.',
        ],
    ]);

    app(StartFiscalYear::class)(2023);

    $checks = static fn (string $code): array => array_column(FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', $code)->select('id'))
        ->where('valid_from', '2023-01-01')->sole()->evidence['checks'] ?? [], 'kind');

    expect($checks('RO.INCOME_TAX.RATE'))->toBe([])
        ->and($checks('RO.CASS.OTHER_INCOME.BANDS_WAGES'))->toBe(['readers_disagree']);
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
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CAS.RATE')->select('id'))
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

    expect(app(StartFiscalYear::class)(2024))->toBe(5)
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
    $bands = static fn (int $year): Reading => new Reading(
        'RO.CASS.OTHER_INCOME.BANDS_WAGES',
        new DateTimeImmutable("{$year}-01-01"),
        new DateTimeImmutable("{$year}-12-31"),
        ['wages' => [6, 12]],
        [6, 12],
        ['kind' => 'law'],
    );
    $found = static fn (string $citation): array => [new LawMatch($section($citation)->id, $citation, $section($citation)->document_version_id, [6, 12])];
    $parameter = FiscalParameter::query()->create(['code' => 'RO.CASS.OTHER_INCOME.BANDS_WAGES', 'value_type' => 'JSON']);
    insertApprovedFiscalParameterVersion($parameter->id, $section('Art. 170 alin. (4)')->id, ['valid_from' => '2023-01-01', 'valid_until' => '2023-12-31', 'value_json' => json_encode(['wages' => [6, 12]], JSON_THROW_ON_ERROR)]);

    app(CandidateRecorder::class)->record([['reading' => $bands(2024), 'matches' => $found('Art. 170 alin. (4)')]], []);
    app(CandidateRecorder::class)->record([['reading' => $bands(2025), 'matches' => $found('Art. 170 alin. (3)')]], []);

    $candidate = static fn (string $from): FiscalParameterVersion => FiscalParameterVersion::query()->where('valid_from', $from)->sole();

    expect($candidate('2024-01-01')->evidence['checks'] ?? [])->toBe([])
        ->and($candidate('2025-01-01')->source_section_id)->toBe($section('Art. 170 alin. (3)')->id)
        ->and($candidate('2025-01-01')->evidence['checks'] ?? [])->toEqual([['kind' => 'paragraph_changed', 'citation' => 'Art. 170 alin. (4)', 'now' => 'Art. 170 alin. (3)', 'year' => 2025]]);
});

it('takes a quote across the points of one paragraph, and a number the law writes with a separator', function (): void {
    $this->travelTo('2023-06-01');
    $version = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2023-01-01');
    heading($version, 'Titlul V Capitolul III', [
        ['Art. 170 alin. (3)', 'Baza anuală de calcul al contribuției de asigurări sociale de sănătate o reprezintă:'],
    ]);
    heading($version, 'Titlul IV Capitolul VI', [
        ['Art. 100 alin. (1)', 'Venitul impozabil lunar din pensii se stabilește prin deducerea din venitul din pensie a sumei neimpozabile lunare de 3.000 lei.'],
    ]);
    $paragraph = SourceSection::query()->where('citation_label', 'Art. 170 alin. (3)')->sole();

    foreach (['lit. a)' => 'nivelul a 6 salarii minime brute pe țară;', 'lit. b)' => 'nivelul de 12 salarii minime brute pe țară.'] as $letter => $text) {
        SourceSection::factory()->create([
            'document_version_id' => $version->id,
            'parent_id' => $paragraph->id,
            'section_type' => SectionType::LITERA,
            'citation_label' => "Art. 170 alin. (3) {$letter}",
            'ordinal' => SourceSection::query()->where('document_version_id', $version->id)->count() + 1,
            'text' => $text,
        ]);
    }

    readers([
        // The rule is written across the paragraph and the points below it, and the reader quotes it
        // as one — labels and all, which is how the paragraphs were given to it.
        'art. 155 alin. (1) lit. c)-h)' => [
            'values' => [6, 12],
            'citation' => 'Art. 170 alin. (3)',
            'quote' => 'Art. 170 alin. (3) Baza anuală de calcul al contribuției de asigurări sociale de sănătate o reprezintă: Art. 170 alin. (3) lit. a) nivelul a 6 salarii minime brute pe țară; Art. 170 alin. (3) lit. b) nivelul de 12 salarii minime brute pe țară.',
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

    expect($value('RO.CASS.OTHER_INCOME.BANDS_WAGES'))->toBe(['wages' => [6, 12]])
        ->and($value('RO.PENSION.TAX_FREE_AMOUNT'))->toBe(['amount_minor' => 300000, 'currency' => 'RON']);
});

it('reads a rule where it was approved, so a year whose paragraph no longer says it proposes nothing', function (): void {
    $this->travelTo('2024-06-01');
    bootstrapIdentityCryptoForTests();
    $version = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2024-01-01');
    heading($version, 'Titlul V Capitolul III', [
        // The paragraph the rule rested on, rewritten: it no longer sets a base for every income.
        ['Art. 170 alin. (4)', 'La încadrarea în plafon nu se iau în calcul veniturile neimpozabile.'],
        // Another paragraph of the same chapter, holding a number like the one looked for.
        ['Art. 174 alin. (6)', 'Baza de calcul este mai mică decât cea corespunzătoare a 12 salarii minime brute pe țară.'],
    ]);
    $approvedOn = SourceSection::query()->where('citation_label', 'Art. 170 alin. (4)')->sole();
    $parameter = FiscalParameter::query()->create(['code' => 'RO.CASS.ALL_INCOME.CAP_WAGES', 'value_type' => 'INTEGER']);
    insertApprovedFiscalParameterVersion($parameter->id, $approvedOn->id, [
        'valid_from' => '2022-01-01',
        'valid_until' => '2022-12-31',
        'value_json' => json_encode(['value' => 12], JSON_THROW_ON_ERROR),
    ]);

    // Whatever it is asked, the reader answers from the paragraph it is given.
    readers(['salarii minime' => [
        'value' => 12,
        'citation' => 'Art. 174 alin. (6)',
        'quote' => 'mai mică decât cea corespunzătoare a 12 salarii minime brute pe țară',
    ]]);

    app(StartFiscalYear::class)(2024);

    // The rule rests on Art. 170 alin. (4), which no longer says it: nothing is proposed for 2024,
    // though another paragraph of the chapter holds a number like the one it used to have.
    expect(FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CASS.ALL_INCOME.CAP_WAGES')->select('id'))
        ->where('valid_from', '2024-01-01')->count())->toBe(0);
});
