<?php

declare(strict_types=1);

namespace Kern\Legislation\Tests\Unit\LegislatieJustRoHtmlParserPreambleTest;

use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\LegislatieJustRoHtmlParser;
use Kern\Legislation\Parsing\ParsedSection;

/**
 * What an order prints outside its articles: ORDIN 1.324/2026 of the Ministry of Finance, as
 * legislatie.just.ro serves it (public, the Official Gazette's text) — changed where a test says.
 *
 * @return list<ParsedSection>
 */
function sections(array $changes = []): array
{
    $html = strtr((string) file_get_contents(__DIR__ . '/../Fixtures/Legislatie/ordin-1324-2026-prospect-tezaur.html'), $changes);

    return (new LegislatieJustRoHtmlParser())->parse($html)->topLevelSections;
}

it('keeps the preamble and the signature where they stand, each a section of its own', function (): void {
    $sections = sections();

    expect(array_map(fn (ParsedSection $s): string => $s->citationLabel, $sections))->toBe(['Preambul', 'Art. 1', 'Art. 2', 'Art. 3', 'Semnătura', 'ANEXĂ'])
        ->and(array_map(fn (ParsedSection $s): int => $s->ordinal, $sections))->toBe([1, 2, 3, 4, 5, 6]);
});

it('keeps the preamble’s lines in order, its dashes as lines of it rather than provisions', function (): void {
    $preamble = sections()[0];
    $lines = explode("\n", $preamble->text);

    expect($preamble->sectionType)->toBe(SectionType::PREAMBUL)
        ->and($preamble->children)->toBe([])
        ->and($lines[0])->toBe('Având în vedere prevederile:')
        ->and($lines[1])->toStartWith('– Ordinului ministrului finanțelor publice nr. 3.139/2017')
        ->and($lines[3])->toBe('în temeiul:')
        ->and($lines[4])->toStartWith('– art. 10 alin. (4) din Hotărârea Guvernului nr. 34/2009')
        ->and($lines[array_key_last($lines)])->toBe('ministrul finanțelor emite următorul ordin:')
        ->and($lines)->toHaveCount(8);
});

it('keeps who signed, where, when, and the number, each on its line', function (): void {
    $signature = sections()[4];

    expect($signature->sectionType)->toBe(SectionType::SEMNATURA)
        ->and(explode("\n", $signature->text))->toBe(['Ministrul finanțelor,', 'Alexandru Nazare', 'București, 30 septembrie 2026.', 'Nr. 1.324.']);
});

it('leaves an act with no signature block without one, its closing lines unkept as before', function (): void {
    $sections = sections(['class="S_SMN"' => 'class="S_X"']);

    expect(array_map(fn (ParsedSection $s): string => $s->citationLabel, $sections))->toBe(['Preambul', 'Art. 1', 'Art. 2', 'Art. 3', 'ANEXĂ']);
});
