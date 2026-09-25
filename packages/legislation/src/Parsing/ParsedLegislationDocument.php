<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing;

use DateTimeImmutable;

/**
 * The full result of parsing one raw document version's bytes
 * ({@see LegislatieJustRoHtmlParser::parse()}). `publicationDate` is `null` whenever the source
 * did not carry a recognisable "Monitorul Oficial" publication line — never guessed.
 *
 * `ambiguousCitationLabels` — added after parsing the real Codul fiscal
 * (`docs/handoff/039-fiscal-code-parser-fix.md`) — lists every citation label the source itself
 * renders for **more than one distinct provision**, after every legitimate structural fix this
 * parser knows how to apply. Confirmed real, evidenced examples: `legislatie.just.ro`'s own
 * markup mislabels one alineat's `_TTL` (its own amendment note names a different number), and
 * once renders one entire paragraph twice verbatim. `topLevelSections` never contains more than
 * one section per such label — the later, colliding occurrence(s) are dropped, never silently
 * renumbered or suffixed (`docs/AGENTS.md` rule: never invent a citation) — because BLUEPRINT.md
 * §30/§39 require a citation to identify exactly one provision, and there is no way to tell which
 * of two colliding occurrences a lawyer would mean without guessing. This list is how that gap is
 * surfaced rather than hidden; a caller/reviewer must resolve each entry by hand against the raw
 * source before treating either occurrence as reliable.
 */
final readonly class ParsedLegislationDocument
{
    /**
     * @param list<ParsedSection> $topLevelSections
     * @param list<string> $ambiguousCitationLabels
     * @param list<ParsedOutlineNode> $outline the table of contents, in reading order, parents first
     */
    public function __construct(
        public ?DateTimeImmutable $publicationDate,
        public array $topLevelSections,
        public array $ambiguousCitationLabels = [],
        public array $outline = [],
    ) {
    }
}
