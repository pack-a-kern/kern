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
use App\Domain\TaxKnowledge\Models\FiscalParameter;
use App\Domain\TaxKnowledge\Models\FiscalParameterVersion;
use App\Domain\TaxKnowledge\Models\FormStructure;
use App\Domain\TaxKnowledge\Models\SourceDocument;
use App\Domain\TaxKnowledge\Models\SourceDocumentVersion;
use App\Domain\TaxKnowledge\Models\SourceOutlineNode;
use App\Domain\TaxKnowledge\Models\SourceSection;
use App\Domain\TaxKnowledge\Repositories\OfficialSourceRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

/** ANAF's page listing the file, the file, and the extraction service reading its formulas. */
function anaf(?string $formulas = null, string $url = FORM_2024): void
{
    Http::fake([
        'static.anaf.ro/static/10/Anaf/Declaratii_R/declaratie_unica.html' => Http::response('<html><body><a href="' . $url . '">Structura</a></body></html>', 200, ['Content-Type' => 'text/html']),
        'static.anaf.ro/*' => Http::response('%PDF-1.4 structure file', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $formulas ??= implode("\n", [
        'cas_baza 2.Bază anuală calcul a CAS N(15) IF bifa_cas_real = 1, THEN cas_baza >= 12*3000 IF bifa_cas_real = 2, THEN cas_baza >= 24*3000',
        'cas_datorat 3.CAS datorată (rd.2 x 25%) N(15) cas_datorat = math.round(cas_baza *25/ 100)',
        'ATT: Baza anuala de calcul a CAS nu pot fi mai mici decât nivelul a 12 salarii minime brute pe ţară, în vigoare în anul pentru care se datorează contribuţia(39.600 lei)',
        'IF bifa_cass_real = 1, THEN cass_baza = 6*3000 IF bifa_cass_real = 2, THEN cass_baza = 12*3000 IF bifa_cass_real = 3, THEN csas_baza = 24*3000',
        'cass_datorat = math.round(cass_baza *10/ 100)',
        'baza_cass_estim_B > 0 baza_cass_estim_B <= 60*3300',
    ]);

    app()->instance(TextExtractionGateway::class, new class ($formulas) implements TextExtractionGateway {
        public function __construct(private readonly string $text)
        {
        }

        public function extractNativeText($pdfStream): NativeTextExtractionResult
        {
            return new NativeTextExtractionResult(1, false, [1 => new PageExtractionResult(1, '1.0', UntrustedDocumentText::wrap($this->text))]);
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
        ['RO.CASS.BASE_WAGES', '2023-01-01', '2023-12-31', ['wages' => [6, 12, 24]], 'Art. 170 alin. (3)'],
        ['RO.CASS.RATE', '2023-01-01', '2023-12-31', ['value' => '0.10'], 'Art. 156 alin. (1)'],
        ['RO.MIN_WAGE', '2023-01-01', '2023-09-30', ['amount_minor' => 300000, 'currency' => 'RON'], 'Art. 1'],
        ['RO.MIN_WAGE', '2023-10-01', '2024-12-31', ['amount_minor' => 330000, 'currency' => 'RON'], 'Art. 1'],
        ['RO.MIN_WAGE', '2025-01-01', null, ['amount_minor' => 405000, 'currency' => 'RON'], 'Art. 1'],
    ])->and($result->notInLaw)->toBe(1);

    // The 60-wage cap of 2024 is not in this law: kept on its file, for a person to see, never a value.
    // Compared by content: a jsonb object keeps no key order.
    expect(FormStructure::query()->sole()->unmatched)->toEqual([[
        'parameter' => 'RO.CASS.BASE_CAP_WAGES',
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
        ->whereIn('fiscal_parameter_id', FiscalParameter::query()->where('code', 'RO.CASS.BASE_WAGES')->select('id'))->sole();

    // Compared by content: a jsonb object keeps no key order.
    expect(collect($wage2023->evidence['checks'] ?? [])->firstWhere('kind', 'confirmed_by_form'))
        ->toEqual(['kind' => 'confirmed_by_form', 'year' => 2023, 'form_year' => 2024, 'form_wage' => 3000, 'url' => FORM_2024])
        ->and(array_column($cassBands->evidence['matches'] ?? [], 'citation'))->toBe(['Art. 170 alin. (3)', 'Art. 170 alin. (4)'])
        ->and($cassBands->evidence['checks'] ?? [])->toContain(['kind' => 'several_paragraphs'])
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
