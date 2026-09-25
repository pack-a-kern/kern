<?php

declare(strict_types=1);

namespace Kern\Legislation\Parsing;

use DateTimeImmutable;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Kern\Legislation\Enums\OutlineLevel;
use Kern\Legislation\Enums\SectionType;
use Kern\Legislation\Parsing\Exceptions\UnparsableLegislationDocumentException;
use RuntimeException;

/**
 * Structural parser for `legislatie.just.ro`'s real, observed HTML markup (`BLUEPRINT.md` §9,
 * this task's brief). The class-name hierarchy below was read directly from a live fetch on
 * 2026-09-08 (the Romanian Constitution, `Public/DetaliiDocumentAfis/47355`, and a decree,
 * `Public/DetaliiDocumentAfis/47355`... see `docs/handoff/018-legislation-ingestion.md` "Sources
 * consulted" for the exact URLs) — **not guessed, not invented**:
 *
 * ```text
 * <span class="S_ART" id="id_artNNN">                        <!-- ARTICOL container -->
 *   <span class="S_ART_TTL" id="..._ttl">Articolul 27</span> <!-- label -->
 *   <span class="S_ART_DEN">...</span>                       <!-- denomination — not captured, see below -->
 *   <span class="S_ART_BDY" id="..._bdy">
 *     <span class="S_PAR" ...>Inviolabilitatea domiciliului</span>   <!-- own text -->
 *     <span class="S_ALN" id="id_litNNN">                    <!-- ALINEAT, nested directly in BDY -->
 *       <span class="S_ALN_TTL" ...>(1)</span>
 *       <span class="S_ALN_BDY" ...>Domiciliul ... <span class="S_LIT" ...>...</span></span>
 *     </span>
 *   </span>
 * </span>
 * ```
 *
 * Nesting is *usually* DOM containment: a section's children are ordinarily the container spans
 * found as direct children of its own `_BDY` element. **This is no longer the whole story** — see
 * `docs/handoff/039-fiscal-code-parser-fix.md` for the full measured evidence, gathered by parsing
 * the real Codul fiscal (Legea 227/2015, 7.6 MB), which is far larger and more amended than the
 * Constitution this parser was first validated against and broke two of this docblock's original
 * assumptions:
 *
 * 1. `collectBodyContent()` now descends **recursively**, not just into `_BDY`'s direct children —
 *    a real, plain wrapper (`S_PAR`) can directly contain a nested container (e.g. Art. 3's eight
 *    fiscal principles are `S_LIT` elements nested *inside* the article's own introductory
 *    `S_PAR`, not siblings of it). A direct-children-only scan silently dropped 233 real `S_LIT`
 *    elements this way — not mislabelled, absent from the parse tree entirely.
 * 2. `groupOrphanContinuations()` repairs a specific, measured tag-nesting defect in the source
 *    itself: when an `ALINEAT`'s own body contains an editorial amendment note, the site's HTML
 *    sometimes closes that `ALINEAT` *before* the lettered items its own text promised, leaving
 *    them as direct siblings of the `ALINEAT` (not nested inside it). See that method's docblock.
 *
 * **Formatting (1.2.0).** A section's text keeps the line breaks between the source's blocks, and a
 * `<table>` — the income-tax brackets, the agricultural norms — is kept as rows and cells with a
 * marker in the text where it stood ({@see SectionText}). A dash item (`S_LIN`, "– …") is a section
 * of its own.
 *
 * **What this parser does NOT capture, honestly, not silently**:
 * - `S_ART_DEN` ("denomination") — observed empty/HTML-comment in every live sample fetched;
 *   discarded rather than guessed at.
 * - `S_TTL`/`S_CAP`/`S_SEC` (Titlu/Capitol/Secțiune) as *sections* — real container levels *above*
 *   `ARTICOL`, but outside `Kern\Legislation\Enums\SectionType`'s scope: they never carry
 *   citable text of their own, only group other sections, and an `ARTICOL`'s `parent_id` stays
 *   `null` under them. Since 1.4.0 they are the document's table of contents instead
 *   ({@see ParsedOutlineNode}), and each article records the heading it stands under. `S_ANX` (Anexă/Annex) is the one sibling wrapper-ish level that
 *   *is* modelled ({@see SectionType::ANEXA}) — confirmed against the real Codul fiscal to carry
 *   its own citable `ALINEAT`/`LITERA`/`PUNCT` content, unlike Titlu/Capitol/Secțiune.
 * - **`S_PCT` (`PUNCT`) was unverified when this parser was first written** — no document
 *   containing numbered "puncte" had been fetched yet. **Now confirmed**: the real Codul fiscal
 *   uses `S_PCT` 631 times (`docs/handoff/039-fiscal-code-parser-fix.md`), including `S_PCT`
 *   nested inside `S_LIT` and vice versa — both handled by the same generic container recursion,
 *   no `PUNCT`-specific code was needed.
 * - **Effective dates.** No structured, reliably-parseable effective-date field was found on the
 *   page — free-text mentions of "în vigoare" are substantive legal content (what the law itself
 *   says takes effect when), not machine-readable version metadata. This parser therefore never
 *   sets a section's or a document's `effective_from`/`effective_until` — see
 *   `Kern\Legislation\Services\LegislationSectionPersister`, which is what always leaves a
 *   freshly-parsed `legislatie.just.ro` version `REQUIRES_REVIEW`, honestly, every time.
 */
final class LegislatieJustRoHtmlParser
{
    /*
     * 1.2.0 — the text keeps its line breaks, tables are kept as rows and cells in place, and a
     * dash item (`S_LIN`) is a section of its own rather than words run into its parent.
     * 1.3.0 — the portal's older markup is read too ({@see LegacyMarkup}), and a point or a dash
     * standing after the provision it belongs to is nested into it.
     * 1.4.0 — the table of contents: titles, chapters and sections, with the articles under each.
     * 1.5.0 — the portal's notes ("Notă") are kept beside the provision they stand in, out of its text.
     */
    public const string VERSION = '1.5.0';

    /** @var array<string, SectionType> */
    private const array CONTAINER_CLASSES = [
        'S_ART' => SectionType::ARTICOL,
        'S_ALN' => SectionType::ALINEAT,
        'S_LIT' => SectionType::LITERA,
        // Unverified — see class docblock.
        'S_PCT' => SectionType::PUNCT,
        // Confirmed against the real Codul fiscal — see class docblock's "Anexe" note.
        'S_ANX' => SectionType::ANEXA,
        // 184 of them in the Codul fiscal consolidation of 2026-08-08; each "– …" item of a list.
        'S_LIN' => SectionType::LINIUTA,
    ];

    /**
     * How deep each kind of provision sits: a paragraph holds letters, a letter points, a point dashes.
     * See {@see groupOrphanContinuations()}.
     *
     * @var array<string, int>
     */
    private const array NESTING_RANK = [
        'ALINEAT' => 1,
        'LITERA' => 2,
        'PUNCT' => 3,
        'LINIUTA' => 4,
    ];

    /**
     * Elements whose text is a block of its own, so a line break separates it from what came
     * before. `S_PAR` is the portal's paragraph; the rest are ordinary HTML blocks.
     *
     * @var list<string>
     */
    private const array BLOCK_CLASSES = ['S_PAR'];

    /** @var list<string> */
    private const array BLOCK_ELEMENTS = ['p', 'div', 'br', 'li'];

    /**
     * Wrapper-container class tokens that never become a {@see ParsedSection} themselves
     * (`BLUEPRINT.md` §6.8 scope, {@see SectionType}'s docblock) but whose own `_TTL` label text is
     * read — verbatim, never invented — to disambiguate an {@see SectionType::ANEXA}'s citation
     * label, since annex numbering restarts per `Titlu` (the real Codul fiscal has three separate
     * documents each rendered "Anexa nr. 1"). Order matters: outermost first, matching the order a
     * citation reads in ("Titlul VII Capitolul XV Anexa").
     *
     * @var list<string>
     */
    private const array TITLE_WRAPPER_CLASSES = ['S_TTL', 'S_CAP', 'S_SEC'];

    /** @var array<string, OutlineLevel> */
    private const array OUTLINE_LEVELS = [
        'S_TTL' => OutlineLevel::TITLU,
        'S_CAP' => OutlineLevel::CAPITOL,
        'S_SEC' => OutlineLevel::SECTIUNE,
    ];

    public function parse(string $html): ParsedLegislationDocument
    {
        if (trim($html) === '') {
            throw UnparsableLegislationDocumentException::emptyContent();
        }

        $document = $this->loadDocument($html);
        (new LegacyMarkup())->normalize($document);
        $root = $document->documentElement;

        if ($root === null) {
            throw UnparsableLegislationDocumentException::noRootElement();
        }

        $outline = [];
        $sections = $this->parseTopLevelSections($root, $outline);
        $seenCitationLabels = [];
        $ambiguousCitationLabels = [];
        $sections = $this->pruneAmbiguousCitations($sections, $seenCitationLabels, $ambiguousCitationLabels);

        return new ParsedLegislationDocument(
            publicationDate: $this->extractPublicationDate($document),
            topLevelSections: $sections,
            ambiguousCitationLabels: array_values(array_unique($ambiguousCitationLabels)),
            outline: $outline,
        );
    }

    /**
     * Last-resort safety net, run once over the whole document after every legitimate structural
     * fix ({@see groupOrphanContinuations()}, the recursive {@see collectBodyContent()}) has
     * already run — see {@see ParsedLegislationDocument::$ambiguousCitationLabels}'s docblock for
     * why this exists and the real, evidenced cases that reach it. Walks the tree in exactly the
     * pre-order (parent, then children in order, then next sibling) that
     * `Kern\Legislation\Services\LegislationSectionPersister` inserts rows in, so "first
     * occurrence wins" here means the same thing there: the *earlier* occurrence in reading order
     * is kept as the real, citable row, and every later section sharing its label — subtree
     * included, since a colliding provision's own children cannot be reliably re-parented either —
     * is dropped and its label recorded, never silently renumbered or suffixed.
     *
     * @param list<ParsedSection> $sections
     * @param array<string, true> $seenCitationLabels
     * @param list<string> $ambiguousCitationLabels
     * @return list<ParsedSection>
     */
    private function pruneAmbiguousCitations(array $sections, array &$seenCitationLabels, array &$ambiguousCitationLabels): array
    {
        $kept = [];

        foreach ($sections as $section) {
            if (isset($seenCitationLabels[$section->citationLabel])) {
                $ambiguousCitationLabels[] = $section->citationLabel;

                continue;
            }

            $seenCitationLabels[$section->citationLabel] = true;

            $children = $this->pruneAmbiguousCitations($section->children, $seenCitationLabels, $ambiguousCitationLabels);

            $kept[] = new ParsedSection(
                $section->sectionType,
                $section->rawLabel,
                $section->citationLabel,
                $section->ordinal,
                $section->text,
                $children,
                $section->tables,
                $section->outlineIndex,
                $section->notes,
            );
        }

        return $kept;
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previousSetting = libxml_use_internal_errors(true);

        try {
            // Prefixing an XML encoding declaration (literal string built below) forces libxml to
            // interpret the byte stream as UTF-8 without inserting a visible processing-instruction
            // node into the tree — a well-known DOMDocument::loadHTML idiom. The alternative, a
            // <meta charset> tag, is not reliably present/first on every page this parser will ever
            // see. NOTE: never write that literal two-character closing delimiter inside a
            // comment in this file — PHP ends the current PHP tag the instant it sees it, even
            // inside a "//" line comment, silently truncating everything after it.
            $document->loadHTML(
                '<?xml encoding="utf-8" ?>' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }

        return $document;
    }

    /**
     * The articles and annexes in reading order, each told which heading it stands under; the
     * headings themselves go to `$outline`.
     *
     * @param  list<ParsedOutlineNode>  $outline
     * @return list<ParsedSection>
     */
    private function parseTopLevelSections(DOMElement $root, array &$outline): array
    {
        $sections = [];
        $ordinal = 1;

        foreach ($this->findTopLevelContainers($root, $outline, null) as [$container, $outlineIndex]) {
            // ARTICOL numbers are unique across the whole act, so (like today) it gets no
            // ancestor context. ANEXA numbers are only unique per-Titlu (verified: the real
            // Codul fiscal has three distinct "Anexa nr. 1"s) — see buildCitationLabel().
            $topLevelContext = $this->matchingContainerToken($container) === 'S_ANX'
                ? $this->ancestorWrapperContext($container)
                : null;

            $parsed = $this->parseContainer($container, $topLevelContext, $ordinal);
            $sections[] = new ParsedSection(
                $parsed->sectionType,
                $parsed->rawLabel,
                $parsed->citationLabel,
                $parsed->ordinal,
                $parsed->text,
                $parsed->children,
                $parsed->tables,
                $outlineIndex,
                $parsed->notes,
            );
            $ordinal++;
        }

        return $sections;
    }

    /**
     * Walks `$container`'s ancestors up to the document root collecting the `_TTL` label of every
     * real `Titlu`/`Capitol`/`Secțiune` wrapper found (outermost first) — verbatim source text,
     * never invented. Returns `null` when no such wrapper exists (an annex directly under the
     * document root, not observed live but not impossible).
     */
    private function ancestorWrapperContext(DOMElement $container): ?string
    {
        $labels = [];
        $node = $container->parentNode;

        while ($node instanceof DOMElement) {
            foreach (self::TITLE_WRAPPER_CLASSES as $token) {
                if (in_array($token, $this->classTokens($node), true)) {
                    $label = $this->directChildTextByToken($node, $token . '_TTL');

                    if ($label !== '') {
                        $labels[] = $label;
                    }

                    break;
                }
            }

            $node = $node->parentNode;
        }

        return $labels === [] ? null : implode(' ', array_reverse($labels));
    }

    /**
     * Depth-first search that stops descending the instant it finds a container element —
     * everything above the first `ARTICOL`/`ALINEAT`/`LITERA`/`PUNCT` on a given branch
     * (a bare `<div>`, or a real `S_TTL`/`S_CAP`/`S_SEC` wrapper) is walked through but never
     * itself becomes a section.
     *
     * A `S_TTL`/`S_CAP`/`S_SEC` wrapper becomes a heading of the table of contents instead — its
     * label, "Capitolul IV", and its name, "Venituri din cedarea folosinței bunurilor" — and every
     * container found inside it is told which heading it stands under.
     *
     * @param  list<ParsedOutlineNode>  $outline
     * @return list<array{0: DOMElement, 1: int|null}> each container, and the index of its heading
     */
    private function findTopLevelContainers(DOMNode $node, array &$outline, ?int $heading): array
    {
        $results = [];

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($this->matchingContainerToken($child) !== null) {
                $results[] = [$child, $heading];

                continue;
            }

            $level = $this->outlineLevel($child);
            $inside = $level === null ? $heading : $this->addHeading($child, $level, $outline, $heading);

            array_push($results, ...$this->findTopLevelContainers($child, $outline, $inside));
        }

        return $results;
    }

    private function outlineLevel(DOMElement $element): ?OutlineLevel
    {
        foreach (self::OUTLINE_LEVELS as $token => $level) {
            if (in_array($token, $this->classTokens($element), true)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * @param  list<ParsedOutlineNode>  $outline
     * @return int the new heading's index
     */
    private function addHeading(DOMElement $wrapper, OutlineLevel $level, array &$outline, ?int $parent): int
    {
        $token = array_search($level, self::OUTLINE_LEVELS, true);
        $label = $this->directChildTextByToken($wrapper, $token . '_TTL');
        $siblings = array_filter($outline, static fn (ParsedOutlineNode $node): bool => $node->parentIndex === $parent);
        $parentPath = $parent === null ? '' : ($outline[$parent]->path ?? '') . ' ';

        $outline[] = new ParsedOutlineNode(
            level: $level,
            label: $label,
            name: $this->directChildTextByToken($wrapper, $token . '_DEN'),
            path: trim($parentPath . $label),
            ordinal: count($siblings) + 1,
            parentIndex: $parent,
        );

        return count($outline) - 1;
    }

    /**
     * @param list<DOMElement> $extraDirectChildren Continuation elements a *sibling* body handed
     *        down because the source's own markup nested them one level too shallow — see
     *        {@see groupOrphanContinuations()}. Always empty for a normal, correctly-nested call.
     */
    private function parseContainer(DOMElement $container, ?string $parentCitationLabel, int $ordinal, array $extraDirectChildren = []): ParsedSection
    {
        $token = $this->matchingContainerToken($container);

        if ($token === null) {
            // Cannot happen: every caller only ever passes an element findTopLevelContainers() or
            // splitBodyContent() already confirmed matches a container token. Thrown, not
            // silently defaulted, per docs/CODE-QUALITY.md §4 ("no catch-all that swallows").
            throw new RuntimeException('parseContainer() called with a non-container element.');
        }

        $sectionType = self::CONTAINER_CLASSES[$token];
        $rawLabel = $this->directChildTextByToken($container, $token . '_TTL');
        $body = $this->directChildElementByToken($container, $token . '_BDY');

        $citationLabel = $this->buildCitationLabel($sectionType, $rawLabel, $parentCitationLabel, $ordinal);

        [$ownText, $childContainers, $tables, $notes] = $body !== null
            ? $this->splitBodyContent($body)
            : ['', [], [], []];

        array_push($childContainers, ...$extraDirectChildren);

        $children = [];
        $childOrdinal = 1;

        foreach ($this->groupOrphanContinuations($childContainers) as $group) {
            $children[] = $this->parseContainer($group->primary, $citationLabel, $childOrdinal, $group->extraDirectChildren);
            $childOrdinal++;
        }

        return new ParsedSection($sectionType, $rawLabel, $citationLabel, $ordinal, $ownText, $children, $tables, null, $notes);
    }

    /**
     * Repairs a specific, measured markup defect in the real Codul fiscal (never observed in the
     * Constitution): when an `ALINEAT`'s own body contains an editorial amendment note (an
     * `S_PAR` reading e.g. "(la 03-09-2021, ... a fost modificată de ...)"), the site's own HTML
     * closes that `ALINEAT`'s tag immediately after the note — *before* the lettered items its
     * introductory sentence promised ("... următoarele situații:"). Those `LITERA` elements land
     * as direct siblings of the `ALINEAT` in the parent body instead of nested inside it.
     * Confirmed live in five places (`docs/handoff/039-fiscal-code-parser-fix.md` has the exact
     * HTML): Art. 40^3 (two groups), Art. 316 (three groups, each preceded by its own dated
     * amendment note).
     *
     * This is a structural repair using only DOM order — never legal-content inference: an element
     * is reattached to the *immediately preceding sibling* in the same body only when that sibling
     * ranks above it ({@see NESTING_RANK}): a `LITERA` after an `ALINEAT`, a `PUNCT` after a
     * `LITERA`, a `LINIUTA` after a `PUNCT`. The later two are evidenced too, 2026-09-22: in the
     * 2026-08-08 consolidation, Art. 26 alin. (1)'s points 1.–3. of lit. c) stand as siblings of the
     * letters while lit. j)'s are nested inside it — the same markup defect, one level down — and in
     * every 2016 consolidation the old markup is flat throughout ({@see LegacyMarkup}). A `LITERA` with no such preceding sibling (e.g. Art. 3's principles, enumerated
     * directly under the article with no alineate at all) is left exactly where it is — that is
     * not a defect, see the class docblock's "Anexe" note for how that case reaches this method's
     * input in the first place.
     *
     * **Deliberately narrow — do not widen without new evidence.** An earlier version of this
     * method also reattached `PUNCT` onto a preceding `ALINEAT`/`PUNCT`, reasoning by analogy
     * rather than evidence. That broke Art. 316 alin. (1) lit. a), which legitimately contains
     * *two independent, correctly-nested* `PUNCT` siblings (pct. 1., pct. 2.) — the rule matched
     * `PUNCT` following `PUNCT` and nested the second `pct.` inside the first
     * (`tests/Unit/Domain/TaxKnowledge/LegislatieJustRoHtmlParserFiscalCodeExtractTest.php` caught
     * it against the real extract). A provision is therefore never nested into one of the *same*
     * rank; only into one that ranks above it.
     *
     * @param list<DOMElement> $elements in document order
     * @return list<OrphanContinuationGroup>
     */
    private function groupOrphanContinuations(array $elements): array
    {
        /** @var list<OrphanContinuationGroup> $groups */
        $groups = [];

        foreach ($elements as $element) {
            $type = $this->sectionTypeOf($element);
            $previousGroup = $groups === [] ? null : $groups[array_key_last($groups)];
            $previousType = $previousGroup === null ? null : $this->sectionTypeOf($previousGroup->primary);

            // A provision that ranks below the one before it — a letter after a paragraph, a point
            // after a letter, a dash after a point — belongs inside it. Never one of equal rank: two
            // points in a row are siblings (the Art. 316 case below).
            $continuesPrevious = $previousGroup !== null
                && $previousType !== null
                && (
                    (isset(self::NESTING_RANK[$type->value], self::NESTING_RANK[$previousType->value])
                        && self::NESTING_RANK[$type->value] > self::NESTING_RANK[$previousType->value])
                    // A point of a point: "3.1." after "3.", in Art. 7's definitions.
                    || ($type === SectionType::PUNCT && $previousType === SectionType::PUNCT
                        && $this->extendsLabel($element, $previousGroup->primary))
                    // The letters and paragraphs of a point — Art. 7 pct. 7.'s a) to e), pct. 36.'s
                    // (1) and (2) — where the definitions put them under numbered points rather
                    // than the other way round.
                    || (in_array($type, [SectionType::LITERA, SectionType::ALINEAT], true) && $previousType === SectionType::PUNCT)
                );

            if ($continuesPrevious) {
                $previousGroup->extraDirectChildren[] = $element;

                continue;
            }

            $groups[] = new OrphanContinuationGroup($element);
        }

        return $groups;
    }

    /**
     * Splits one container's `_BDY` element into (a) its own text and (b) the container elements
     * that become this section's nested children — searched by **recursive descent**, not merely
     * `_BDY`'s direct children.
     *
     * The original implementation (this project's first, validated only against the
     * Constitution) scanned direct children only, on the documented assumption that "nesting is
     * DOM containment ... never deeper". Parsing the real Codul fiscal (7.6 MB, far more amended)
     * disproved that assumption with real, measured evidence
     * (`docs/handoff/039-fiscal-code-parser-fix.md`): **233 real `S_LIT` elements** — e.g. Art. 3's
     * eight fiscal principles — are direct children of a plain `S_PAR` wrapper (an inline
     * paragraph span, not a container), which is itself the direct child of a `_BDY`. A
     * direct-children-only scan folds that whole `S_PAR` subtree into the parent's own text
     * (via `$node->textContent`, silently including the nested letters' text without ever giving
     * them their own row) and never finds the `S_LIT` elements inside it at all — not
     * mislabelled, genuinely absent from the parse tree.
     *
     * This method now walks every descendant (depth-first, document order — the same strategy
     * {@see findTopLevelContainers()} already used at the document root), stopping the descent the
     * instant it finds a container token (that subtree becomes a child, its own text is never
     * touched here) or a non-citable annotation (that subtree is dropped entirely, see below).
     * Only a genuine dead end — a text node, or an element with neither a container token nor an
     * annotation token — contributes to `text`.
     *
     * Two kinds of elements are kept out of the text, at *any* depth — neither text nor a section:
     * - A `_SHORT` element (`S_LIT_SHORT`, the collapsed-view UI duplicate `" ... "` placeholder),
     *   dropped.
     * - An `S_NTA` element ("Notă") — the portal's commentary, not the law's own words, so never part
     *   of `text`. Kept apart instead, as the fourth element: it is where the portal copies what an
     *   amending act says about *when* its changes apply — "prevederile art. 6 … se aplică începând
     *   cu anul fiscal 2026" — which the consolidation's own date does not say (212 of the 301 notes
     *   in the 2026-08-08 consolidation of the Codul fiscal say something of the kind).
     *
     * The text keeps a line break between the source's own blocks (an `S_PAR`, a `<p>`, a `<br>`),
     * and a `<table>` is taken out whole: its rows and cells go to the third element, a marker line
     * holds its place in the text ({@see SectionText::tableMarker()}).
     *
     * @return array{0: string, 1: list<DOMElement>, 2: list<list<list<string>>>, 3: list<string>}
     */
    private function splitBodyContent(DOMElement $body): array
    {
        $textParts = [];
        $childContainers = [];
        $tables = [];
        $notes = [];

        $this->collectBodyContent($body, $textParts, $childContainers, $tables, $notes);

        // Joined with a space, as before: two inline elements with nothing between them in the
        // markup are still two words.
        return [$this->normalizeLines(implode(' ', $textParts)), $childContainers, $tables, $notes];
    }

    /**
     * @param list<string> $textParts
     * @param list<DOMElement> $childContainers
     * @param list<list<list<string>>> $tables
     * @param list<string> $notes
     */
    private function collectBodyContent(DOMElement $node, array &$textParts, array &$childContainers, array &$tables, array &$notes): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $textParts[] = $child->textContent;

                continue;
            }

            if ($child instanceof DOMComment || ! $child instanceof DOMElement) {
                continue;
            }

            if (in_array('S_NTA', $this->classTokens($child), true)) {
                // "Notă ... Potrivit alin. (2) al art. 10 din …" — the label is the portal's own.
                $note = (string) preg_replace('/^Not[ăa]\s*(?:\.{3}|…)?\s*/u', '', $this->normalizeLines($child->textContent));

                if ($note !== '') {
                    $notes[] = $note;
                }

                continue;
            }

            if ($this->isNonCitableAnnotation($child)) {
                continue;
            }

            if ($this->matchingContainerToken($child) !== null) {
                $childContainers[] = $child;

                continue;
            }

            if (strtolower($child->nodeName) === 'table') {
                $tables[] = $this->tableRows($child);
                $textParts[] = "\n" . SectionText::tableMarker(count($tables)) . "\n";

                continue;
            }

            $isBlock = $this->isBlock($child);

            if ($isBlock) {
                $textParts[] = "\n";
            }

            // A plain wrapper (S_PAR, S_LGI, <a>/<b>/...) — recurse rather than flattening its
            // whole subtree into text, since it may itself directly contain a container (the
            // Art. 3 case above).
            $this->collectBodyContent($child, $textParts, $childContainers, $tables, $notes);

            if ($isBlock) {
                $textParts[] = "\n";
            }
        }
    }

    /** Whether a point's label continues another's: "3.1." continues "3.", "2." does not continue "1.". */
    private function extendsLabel(DOMElement $point, DOMElement $previous): bool
    {
        $label = $this->directChildTextByToken($point, 'S_PCT_TTL');
        $before = $this->directChildTextByToken($previous, 'S_PCT_TTL');

        return $before !== '' && str_ends_with($before, '.') && strlen($label) > strlen($before) && str_starts_with($label, $before);
    }

    private function isBlock(DOMElement $element): bool
    {
        return in_array(strtolower($element->nodeName), self::BLOCK_ELEMENTS, true)
            || array_intersect($this->classTokens($element), self::BLOCK_CLASSES) !== [];
    }

    /**
     * A table as rows of cell texts — its own rows only, not those of a table nested in a cell.
     *
     * @return list<list<string>>
     */
    private function tableRows(DOMElement $table): array
    {
        $rows = [];

        foreach ((new DOMXPath($table->ownerDocument ?? new DOMDocument()))->query('.//tr', $table) ?: [] as $row) {
            if (! $row instanceof DOMElement || $this->closestTable($row) !== $table) {
                continue;
            }

            $cells = [];

            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = $this->normalizeWhitespace($cell->textContent);
                }
            }

            if (array_filter($cells, static fn (string $cell): bool => $cell !== '') !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function closestTable(DOMElement $element): ?DOMElement
    {
        $node = $element->parentNode;

        while ($node instanceof DOMElement) {
            if (strtolower($node->nodeName) === 'table') {
                return $node;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    private function buildCitationLabel(SectionType $type, string $rawLabel, ?string $parentCitationLabel, int $ordinal): string
    {
        $parentPrefix = $parentCitationLabel ?? '';

        return match ($type) {
            SectionType::ARTICOL => $this->articleCitationLabel($rawLabel, $ordinal),
            SectionType::ALINEAT => trim($parentPrefix . ' alin. ' . ($rawLabel !== '' ? $rawLabel : "({$ordinal})")),
            SectionType::LITERA => trim($parentPrefix . ' lit. ' . ($rawLabel !== '' ? $rawLabel : "{$ordinal})")),
            SectionType::PUNCT => trim($parentPrefix . ' pct. ' . ($rawLabel !== '' ? $rawLabel : "{$ordinal}.")),
            // $parentCitationLabel here is the Titlu/Capitol/Secțiune wrapper chain
            // (see ancestorWrapperContext()), not a sibling section's citation — annex numbering
            // restarts per Titlu, so it is the disambiguator an ANEXA needs and no other type does.
            SectionType::ANEXA => trim($parentPrefix . ' ' . ($rawLabel !== '' ? $rawLabel : "Anexa #{$ordinal}")),
            // A dash carries no number of its own — its label is "–" — so it is cited by position,
            // the way a lawyer counts them: "liniuța a 2-a" is the second.
            SectionType::LINIUTA => trim($parentPrefix . ' liniuța ' . $ordinal),
        };
    }

    /**
     * `"Articolul 27"` -> `"Art. 27"`. Falls back to `"Art. #{ordinal}"` (a structural position,
     * never fabricated legal content) only when the source's own label is empty or unparseable —
     * observed to happen for `"Articolul UNIC"`-only decrees are still handled correctly since
     * `"UNIC"` is preserved verbatim (`"Art. UNIC"`), this fallback is for the genuinely-missing
     * case only.
     */
    private function articleCitationLabel(string $rawLabel, int $ordinal): string
    {
        $withoutPrefix = preg_replace('/^articolul\s*/ui', '', trim($rawLabel));
        $number = $withoutPrefix !== null ? trim($withoutPrefix, " \t\n\r\0\x0B.") : '';

        return $number !== '' ? "Art. {$number}" : "Art. #{$ordinal}";
    }

    private function extractPublicationDate(DOMDocument $document): ?DateTimeImmutable
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' S_PUB_BDY ')]");

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $first = $nodes->item(0);

        if (! $first instanceof DOMElement) {
            return null;
        }

        return RomanianDateParser::parse($first->textContent);
    }

    private function directChildTextByToken(DOMElement $parent, string $token): string
    {
        $element = $this->directChildElementByToken($parent, $token);

        return $element === null ? '' : $this->normalizeWhitespace($element->textContent);
    }

    private function directChildElementByToken(DOMElement $parent, string $token): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($token, $this->classTokens($child), true)) {
                return $child;
            }
        }

        return null;
    }

    private function matchingContainerToken(DOMElement $element): ?string
    {
        $classes = $this->classTokens($element);

        foreach (array_keys(self::CONTAINER_CLASSES) as $token) {
            if (in_array($token, $classes, true)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Resolves `$element`'s {@see SectionType} — only ever called on an element a caller already
     * confirmed matches a container token (via {@see matchingContainerToken()}), so the exception
     * path can't happen; kept explicit rather than a silently-nullable lookup per
     * docs/CODE-QUALITY.md §4 ("no catch-all that swallows").
     */
    private function sectionTypeOf(DOMElement $element): SectionType
    {
        $token = $this->matchingContainerToken($element);

        if ($token === null) {
            throw new RuntimeException('sectionTypeOf() called with a non-container element.');
        }

        return self::CONTAINER_CLASSES[$token];
    }

    private function isNonCitableAnnotation(DOMElement $element): bool
    {
        $classes = $this->classTokens($element);

        if (in_array('S_NTA', $classes, true)) {
            return true;
        }

        foreach ($classes as $token) {
            if (str_ends_with($token, '_SHORT')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function classTokens(DOMElement $element): array
    {
        $class = trim($element->getAttribute('class'));

        return $class === '' ? [] : (preg_split('/\s+/', $class) ?: []);
    }

    /**
     * Whitespace collapsed within each line, blank lines dropped, line breaks kept.
     */
    private function normalizeLines(string $text): string
    {
        $lines = array_map($this->normalizeWhitespace(...), preg_split('/\R/u', $text) ?: []);

        return implode("\n", array_values(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
