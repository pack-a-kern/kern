<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\TaxKnowledge\FiscalParameters\ReadFiscalParametersTest;

use App\Domain\Documents\Extraction\DataTransferObjects\NativeTextExtractionResult;
use App\Domain\Documents\Extraction\DataTransferObjects\PageExtractionResult;
use App\Domain\Documents\Extraction\DataTransferObjects\UntrustedDocumentText;
use App\Domain\Documents\Extraction\TextExtractionGateway;
use App\Domain\Identity\Models\User;
use App\Domain\TaxKnowledge\Enums\OutlineLevel;
use App\Domain\TaxKnowledge\Enums\RuleApprovalStatus;
use App\Domain\TaxKnowledge\Enums\SectionType;
use App\Domain\TaxKnowledge\FiscalParameters\Actions\ReadFiscalParameters;
use App\Domain\TaxKnowledge\FiscalParameters\Actions\StartFiscalYear;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawMatch;
use App\Domain\TaxKnowledge\FiscalParameters\Matching\LawMatcher;
use App\Domain\TaxKnowledge\FiscalParameters\ParameterCatalog;
use App\Domain\TaxKnowledge\FiscalParameters\Reading\Reading;
use App\Domain\TaxKnowledge\FiscalParameters\Recording\CandidateRecorder;
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Models\FormStructure;
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
use RuntimeException;

/**
 * The fiscal values read end to end: the minimum wage from its decisions, the rest from a D212
 * structure file, each looked for in the law, proposed as candidates.
 *
 * The law's paragraphs are the Codul fiscal's own words (the 2026-08-08 consolidation, as read in);
 * the decisions' sentence is HG 1447/2022's, HG 900/2023's and HG 1506/2024's; the form's formulas
 * are quoted from ANAF's 2024 D212 structure file as the extraction service reads it.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
});

const FORM_2024 = 'https://static.anaf.ro/static/10/Anaf/Declaratii_R/AplicatiiDec/structura_declaratieUnica_2024_v1.0.0_10012024.pdf';

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
 * ANAF's page listing the files, the files, and the extraction service reading their formulas —
 * each file carries its own address, so the service tells them apart.
 *
 * @param  array<string, string>  $more  further files: address => the formulas it reads as
 */
function anaf(?string $formulas = null, string $url = FORM_2024, array $more = []): void
{
    $links = implode('', array_map(static fn (string $link): string => '<a href="' . $link . '">Structura</a>', [$url, ...array_keys($more)]));

    Http::fake([
        'static.anaf.ro/static/10/Anaf/Declaratii_R/declaratie_unica.html' => Http::response('<html><body>' . $links . '</body></html>', 200, ['Content-Type' => 'text/html']),
        'static.anaf.ro/*' => static fn (HttpRequest $request) => Http::response('%PDF-1.4 ' . $request->url(), 200, ['Content-Type' => 'application/pdf']),
    ]);

    $formulas ??= implode("\n", [
        'cas_baza 2.Bază anuală calcul a CAS N(15) IF bifa_cas_real = 1, THEN cas_baza >= 12*3000 IF bifa_cas_real = 2, THEN cas_baza >= 24*3000',
        'cas_datorat 3.CAS datorată (rd.2 x 25%) N(15) cas_datorat = math.round(cas_baza *25/ 100)',
        'ATT: Baza anuala de calcul a CAS nu pot fi mai mici decât nivelul a 12 salarii minime brute pe ţară, în vigoare în anul pentru care se datorează contribuţia(39.600 lei)',
        'IF bifa_cass_real = 1, THEN cass_baza = 6*3000 IF bifa_cass_real = 2, THEN cass_baza = 12*3000 IF bifa_cass_real = 3, THEN csas_baza = 24*3000',
        'cass_datorat = math.round(cass_baza *10/ 100)',
        'baza_cass_estim_B > 0 baza_cass_estim_B <= 60*3300',
    ]);

    app()->instance(TextExtractionGateway::class, new class ([$url => $formulas, ...$more]) implements TextExtractionGateway {
        /** @param array<string, string> $texts */
        public function __construct(private readonly array $texts)
        {
        }

        public function extractNativeText($pdfStream): NativeTextExtractionResult
        {
            $address = substr((string) stream_get_contents($pdfStream), strlen('%PDF-1.4 '));

            return new NativeTextExtractionResult(1, false, [1 => new PageExtractionResult(1, '1.0', UntrustedDocumentText::wrap($this->texts[$address] ?? ''))]);
        }
    });
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

it('reads every value from its source and proposes the ones the law says', function (): void {
    codulFiscal();
    decisions();
    anaf();

    $result = app(ReadFiscalParameters::class)();

    expect(candidates())->toBe([
        ['RO.CAS.BASE_WAGES', '2023-01-01', '2023-12-31', ['wages' => [12, 24]], 'Art. 148 alin. (2)'],
        // "12 salarii … (39.600 lei)" counts in 3 300 lei: the wage of 1 January 2024, so it is 2024's.
        ['RO.CAS.BASE_WAGES', '2024-01-01', '2024-12-31', ['wages' => [12]], 'Art. 148 alin. (2)'],
        // Chapter II's 25%, never Chapter IX's.
        ['RO.CAS.RATE', '2023-01-01', '2023-12-31', ['value' => '0.25'], 'Art. 138 alin. (1)'],
        ['RO.CASS.OTHER_INCOME.BANDS_WAGES', '2023-01-01', '2023-12-31', ['wages' => [6, 12, 24]], 'Art. 170 alin. (3)'],
        ['RO.CASS.RATE', '2023-01-01', '2023-12-31', ['value' => '0.10'], 'Art. 156 alin. (1)'],
        ['RO.MIN_WAGE', '2023-01-01', '2023-09-30', ['amount_minor' => 300000, 'currency' => 'RON'], 'Art. 1'],
        ['RO.MIN_WAGE', '2023-10-01', '2024-12-31', ['amount_minor' => 330000, 'currency' => 'RON'], 'Art. 1'],
        ['RO.MIN_WAGE', '2025-01-01', null, ['amount_minor' => 405000, 'currency' => 'RON'], 'Art. 1'],
    ])->and($result->notInLaw)->toBe(1);

    // The 60-wage cap of 2024 is not in this law: kept on its file, for a person to see, never a value.
    // Compared by content: a jsonb object keeps no key order.
    expect(FormStructure::query()->sole()->unmatched)->toEqual([[
        'parameter' => 'RO.CASS.INDEPENDENT.CAP_WAGES',
        'kind' => 'not_in_law',
        'year' => 2024,
        'numbers' => '60',
        'quote' => 'baza_cass_estim_B <= 60*3300',
    ]]);

    // Every value is only proposed; none counts yet.
    expect(FiscalParameterVersion::query()->where('status', '<>', RuleApprovalStatus::CANDIDATE->value)->count())->toBe(0);
});

it('keeps what did not agree beside the value, and every paragraph that says it', function (): void {
    codulFiscal();
    decisions();
    anaf();

    app(ReadFiscalParameters::class)();

    $wage2023 = FiscalParameterVersion::query()->where('valid_from', '2023-01-01')
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.MIN_WAGE')->select('id'))->sole();
    $cassBands = FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CASS.OTHER_INCOME.BANDS_WAGES')->select('id'))->sole();

    // Compared by content: a jsonb object keeps no key order.
    expect(collect($wage2023->evidence['checks'] ?? [])->firstWhere('kind', 'confirmed_by_form'))
        ->toEqual(['kind' => 'confirmed_by_form', 'year' => 2023, 'form_year' => 2024, 'form_wage' => 3000, 'url' => FORM_2024])
        ->and(array_column($cassBands->evidence['matches'] ?? [], 'citation'))->toBe(['Art. 170 alin. (3)', 'Art. 170 alin. (4)'])
        ->and($cassBands->evidence['checks'] ?? [])->toContain(['kind' => 'several_paragraphs', 'count' => 2])
        ->and($cassBands->evidence['readings'][0]['quotes'] ?? [])->toBe(['cass_baza = 6*3000', 'cass_baza = 12*3000', 'csas_baza = 24*3000']);
});

it('changes nothing decided when read again, and never proposes a rejected value twice', function (): void {
    codulFiscal();
    decisions();
    anaf();

    app(ReadFiscalParameters::class)();
    $first = FiscalParameterVersion::query()->pluck('id')->sort()->values()->all();

    $rejected = FiscalParameterVersion::query()->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CAS.RATE')->select('id'))->sole();
    $rejected->forceFill(['status' => RuleApprovalStatus::REJECTED->value])->save();

    app(ReadFiscalParameters::class)();

    expect(FiscalParameterVersion::query()->pluck('id')->sort()->values()->all())->toBe($first)
        ->and($rejected->fresh()?->status)->toBe(RuleApprovalStatus::REJECTED);
});

it('reads only the decisions when only a decision is new', function (): void {
    codulFiscal();
    decisions();
    Http::fake();

    app(ReadFiscalParameters::class)(forms: false);

    Http::assertNothingSent();
    expect(array_values(array_unique(array_map(static fn (mixed $code): string => is_string($code) ? $code : '', array_column(candidates(), 0)))))->toBe(['RO.MIN_WAGE']);
});

it('proposes a new year from the year before, if its law still says each value', function (): void {
    codulFiscal();
    decisions();
    anaf();
    bootstrapIdentityCryptoForTests();

    app(ReadFiscalParameters::class)();

    $cassRate = FiscalParameterVersion::query()->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CASS.RATE')->select('id'))->sole();
    $cassRate->approveByPerson(User::factory()->create()->id);

    expect(app(StartFiscalYear::class)(2024))->toBe(1);

    $carried = FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CASS.RATE')->select('id'))
        ->where('valid_from', '2024-01-01')
        ->sole();

    expect($carried->status)->toBe(RuleApprovalStatus::CANDIDATE)
        ->and($carried->value_json)->toBe(['value' => '0.10'])
        ->and($carried->evidence['readings'][0] ?? [])->toMatchArray(['kind' => 'carried', 'from_year' => 2023]);
});

it('reads a wage that changed during the year as that year\'s, and warns against neither', function (): void {
    codulFiscal();
    minimumWageDecision(
        'HOTARARE 935/2019',
        'HOTĂRÂRE nr. 935 din 13 decembrie 2019 pentru stabilirea salariului de bază minim brut pe ţară garantat în plată',
        '2019-12-16',
        'Începând cu data de 1 ianuarie 2020, salariul de bază minim brut pe țară garantat în plată, prevăzut la art. 164 alin. (1) din Legea nr. 53/2003 - Codul muncii, republicată , cu modificările și completările ulterioare, se stabilește în bani, fără a include sporuri și alte adaosuri, la suma de 2.230 lei lunar'
    );
    minimumWageDecision(
        'HOTARARE 4/2021',
        'HOTĂRÂRE nr. 4 din 13 ianuarie 2021 pentru stabilirea salariului de bază minim brut pe ţară garantat în plată',
        '2021-01-13',
        'Începând cu data intrării în vigoare a prezentei hotărâri, salariul de bază minim brut pe țară garantat în plată, prevăzut la art. 164 alin. (1) din Legea nr. 53/2003 - Codul muncii, republicată , cu modificările și completările ulterioare, se stabilește în bani, fără a include sporuri și alte adaosuri, la suma de 2.300 lei lunar'
    );
    // The 2022 file, about 2021 income: 12 wages of 2 300.
    anaf('34. cass_baza Baza anuală de calcul a CASS N(15) cass_baza >= 0 1150<=cass_baza <=27600 cass_baza trebuie sa fie multiplu de 1150', 'https://static.anaf.ro/static/10/Anaf/Declaratii_R/AplicatiiDec/structura_declaratieUnica_2022_05012022.pdf');

    app(ReadFiscalParameters::class)();

    $checks = static fn (string $from): array => array_column(FiscalParameterVersion::query()
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.MIN_WAGE')->select('id'))
        ->where('valid_from', $from)
        ->sole()->evidence['checks'] ?? [], 'kind');

    expect($checks('2020-01-01'))->toBe([])
        ->and($checks('2021-01-13'))->toBe(['confirmed_by_form']);
});

it('finds a value in the wording the portal\'s note dates from before the next consolidation', function (): void {
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

    $incomeTax = app(ParameterCatalog::class)->find('RO.INCOME_TAX.RATE') ?? throw new RuntimeException('RO.INCOME_TAX.RATE is configured.');
    $law = app(LawMatcher::class);

    $in2018 = $law->find($incomeTax, [10], new DateTimeImmutable('2018-01-01'));
    $in2017 = $law->find($incomeTax, [16], new DateTimeImmutable('2017-06-01'));

    expect(array_map(static fn (LawMatch $match): array => [$match->citation, $match->documentVersionId, $match->datedByNote], $in2018))
        ->toBe([['Art. 64 alin. (1)', $new->id, '2018-01-01']])
        ->and(array_map(static fn (LawMatch $match): array => [$match->documentVersionId, $match->datedByNote], $in2017))
        ->toBe([[$old->id, null]])
        // 16% is not the law on 1 January 2018 any more.
        ->and($law->find($incomeTax, [16], new DateTimeImmutable('2018-01-01')))->toBe([])
        ->and(array_map(static fn (LawMatch $match): array => [$match->documentVersionId, $match->datedByNote], $law->find($incomeTax, [10], new DateTimeImmutable('2023-06-01'))))
        ->toBe([[$later->id, null]]);
});

it('reads the thresholds of one year from two files as one list, the fuller reading', function (): void {
    codulFiscal();
    decisions();
    // The 2024 file's estimate names only the least of CAS's for 2024; the 2025 file both.
    anaf(
        'ATT: Baza anuala de calcul a CAS nu pot fi mai mici decât nivelul a 12 salarii minime brute pe ţară, în vigoare în anul pentru care se datorează contribuţia(39.600 lei)',
        FORM_2024,
        ['https://static.anaf.ro/static/10/Anaf/Declaratii_R/AplicatiiDec/structura_D212_2025_v1.0.3_01082025.pdf' => 'IF bifa_cas_real = 1, THEN cas_baza >= 12*3300 IF bifa_cas_real = 2, THEN cas_baza >= 24*3300'],
    );

    app(ReadFiscalParameters::class)();

    $cas = FiscalParameterVersion::query()->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CAS.BASE_WAGES')->select('id'))->sole();

    expect($cas->value_json)->toBe(['wages' => [12, 24]])
        ->and(array_column($cas->evidence['readings'] ?? [], 'form_year'))->toEqualCanonicalizing([2024, 2025])
        ->and(array_column($cas->evidence['checks'] ?? [], 'kind'))->not->toContain('conflicting');
});

it('keeps a rule to the paragraph it was approved on for another year, and says when that one no longer says it', function (): void {
    codulFiscal();
    $section = static fn (string $citation): SourceSection => SourceSection::query()->where('citation_label', $citation)->sole();
    $bands = static fn (int $year): Reading => new Reading(
        'RO.CASS.OTHER_INCOME.BANDS_WAGES',
        new DateTimeImmutable("{$year}-01-01"),
        new DateTimeImmutable("{$year}-12-31"),
        ['wages' => [6, 12, 24]],
        [6, 12, 24],
        ['kind' => 'form'],
    );
    $found = static fn (string ...$citations): array => array_values(array_map(
        static fn (string $citation): LawMatch => new LawMatch($section($citation)->id, $citation, $section($citation)->document_version_id, [6]),
        $citations,
    ));
    $parameter = FiscalParameter::query()->create(['code' => 'RO.CASS.OTHER_INCOME.BANDS_WAGES', 'value_type' => 'JSON']);
    insertApprovedFiscalParameterVersion($parameter->id, $section('Art. 170 alin. (4)')->id, ['valid_from' => '2023-01-01', 'valid_until' => '2023-12-31', 'value_json' => json_encode(['wages' => [6, 12, 24]], JSON_THROW_ON_ERROR)]);

    app(CandidateRecorder::class)->record([['reading' => $bands(2024), 'matches' => $found('Art. 170 alin. (3)', 'Art. 170 alin. (4)')]], []);
    app(CandidateRecorder::class)->record([['reading' => $bands(2025), 'matches' => $found('Art. 170 alin. (3)')]], []);

    $candidate = static fn (string $from): FiscalParameterVersion => FiscalParameterVersion::query()->where('valid_from', $from)->sole();

    expect($candidate('2024-01-01')->source_section_id)->toBe($section('Art. 170 alin. (4)')->id)
        ->and($candidate('2024-01-01')->evidence['checks'] ?? [])->toBe([])
        ->and($candidate('2025-01-01')->source_section_id)->toBe($section('Art. 170 alin. (3)')->id)
        ->and($candidate('2025-01-01')->evidence['checks'] ?? [])->toEqual([['kind' => 'paragraph_changed', 'citation' => 'Art. 170 alin. (4)']]);
});

it('finds a threshold its lettered points say between them in the paragraph they stand under', function (): void {
    $version = lawVersion('LEGE 227/2015', 'LEGE', 'LEGE nr. 227 din 8 septembrie 2015 privind Codul fiscal', '2024-01-01');
    heading($version, 'Titlul V Capitolul II', [
        ['Art. 148 alin. (4)', 'Baza anuală de calcul o reprezintă:'],
        ['Art. 148 alin. (5)', 'Venitul ales nu poate fi mai mic decât nivelul de 12 salarii minime brute pe țară.'],
    ]);
    $paragraph = SourceSection::query()->where('citation_label', 'Art. 148 alin. (4)')->sole();

    foreach (['lit. a)' => 'nivelul de 12 salarii minime brute pe țară, între 12 salarii minime brute pe țară și 24 de salarii minime brute pe țară;', 'lit. b)' => 'nivelul de 24 de salarii minime brute pe țară.'] as $letter => $text) {
        SourceSection::factory()->create([
            'document_version_id' => $version->id,
            'parent_id' => $paragraph->id,
            'section_type' => SectionType::LITERA,
            'citation_label' => "Art. 148 alin. (4) {$letter}",
            'ordinal' => SourceSection::query()->where('document_version_id', $version->id)->count() + 1,
            'text' => $text,
        ]);
    }

    $definition = app(ParameterCatalog::class)->find('RO.CAS.BASE_WAGES') ?? throw new RuntimeException('CAS thresholds are not configured.');

    $found = app(LawMatcher::class)->find($definition, [12, 24], new DateTimeImmutable('2024-01-01'));

    expect(array_map(static fn (LawMatch $match): array => [$match->citation, $match->says], $found))
        ->toBe([['Art. 148 alin. (4)', [12, 24]], ['Art. 148 alin. (5)', [12]]]);
});
