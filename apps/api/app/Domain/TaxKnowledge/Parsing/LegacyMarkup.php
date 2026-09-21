<?php

declare(strict_types=1);

namespace App\Domain\TaxKnowledge\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Rewrites the portal's older markup into the one {@see LegislatieJustRoHtmlParser} reads.
 *
 * The same provision is marked up differently from one consolidation to the next, measured on the
 * Codul fiscal on 2026-09-22 (fixtures `codul-fiscal-2016-01-01-art-7-26-105-170.html` and its 2016-03-25 and 2026-08-08 siblings):
 *
 *  - **2016-01-01, 2016-01-03, 2016-08-12, 2016-09-05.** Everything under an article is an `S_LIT`
 *    with an empty label, the label written at the start of its text: "(1) Contribuabilul…" is a
 *    paragraph, "a) rezerva legală…" a letter, all of them siblings.
 *  - **Until 2016-12-06.** A point is a plain line, "1. sunt neîncasate…", and a dash item one too,
 *    "- plan de rambursare a datoriilor;".
 *  - **Until 2021.** A table is drawn in box characters — `┌───┬───┐`, `│ 1.│Cereale│` — one line per
 *    `S_PAR` inside an `S_PRE`, and it stands after the provision it belongs to, not inside it.
 *
 * Left alone, "Art. 170 alin. (1)" of 2016 would be "Art. 170 lit. 1)", and the history would show
 * a provision vanishing in 2016 and another appearing — the same words, never changed. Rewritten,
 * every consolidation reads with the same citations, and nesting is left to the parser's one rule
 * for siblings that belong inside the provision before them.
 *
 * Each rewrite applies only where its old form is present: a page with real points is not searched
 * for lines that start with a number.
 */
final class LegacyMarkup
{
    /**
     * A paragraph's label written into its text: "(1)", "(1^1)" — or "5)", the bracket the source
     * itself dropped in a few places (Art. 462 alin. (5), Art. 464 alin. (4) of 2016-01-01).
     */
    private const string PARAGRAPH_PREFIX = '/^\s*\(?(\d+(?:\^\d+)?)\)\s*/u';

    /** A letter's label written into its text: "a)", "a^1)", "aa)" — an annex runs past z. */
    private const string LETTER_PREFIX = '/^\s*([a-zăâîșşțţ]{1,2}(?:\^\d+)?\))\s*/u';

    /** A point written as a line: "1. …", "1^1. …", "3.1. …" — a point of point 3. */
    private const string POINT_PREFIX = '/^\s*(\d+(?:\^\d+)?(?:\.\d+)*\.)\s+/u';

    /** A dash item written as a line: "- …", "– …". */
    private const string DASH_PREFIX = '/^\s*([-–])\s+/u';

    /** Box-drawing characters: a line made only of them (and spaces) separates two rows. */
    private const string BOX_RULE = '/^[\s─━┌┐└┘├┤┬┴┼╔╗╚╝╠╣╦╩╬═╞╡╪╟╢╫]+$/u';

    private const string BOX_CELL = '│';

    private DOMXPath $xpath;

    public function normalize(DOMDocument $document): void
    {
        $this->xpath = new DOMXPath($document);

        foreach ($this->byClass('S_LIT') as $letter) {
            $this->labelInlineLetter($letter);
        }

        if ($this->byClass('S_PCT') === []) {
            foreach ($this->byClass('S_PAR') as $line) {
                $this->lineToContainer($line, self::POINT_PREFIX, 'S_PCT');
            }
        }

        if ($this->byClass('S_LIN') === []) {
            foreach ($this->byClass('S_PAR') as $line) {
                $this->lineToContainer($line, self::DASH_PREFIX, 'S_LIN', '–');
            }
        }

        foreach ($this->byClass('S_PRE') as $pre) {
            $this->boxTableToTable($pre);
        }
    }

    /**
     * An `S_LIT` with an empty label whose text opens with one — a paragraph, or a letter — and an
     * `S_LIT` labelled with a number, which is a paragraph whatever its markup says: a letter's label
     * is never a digit (Art. 464 alin. (4) of 2016-03-25 is marked `S_LIT` "4)").
     */
    private function labelInlineLetter(DOMElement $letter): void
    {
        $title = $this->part($letter, 'S_LIT_TTL');
        $body = $this->part($letter, 'S_LIT_BDY');

        if ($title === null || $body === null) {
            return;
        }

        $ownLabel = trim($title->textContent);

        if ($ownLabel !== '' && preg_match(self::PARAGRAPH_PREFIX, $ownLabel, $match) === 1 && trim((string) preg_replace(self::PARAGRAPH_PREFIX, '', $ownLabel)) === '') {
            $this->makeParagraph($letter, $title, $body, $match[1]);

            return;
        }

        if ($ownLabel !== '') {
            return;
        }

        if ($this->takePrefix($body, self::PARAGRAPH_PREFIX, $label)) {
            $this->makeParagraph($letter, $title, $body, $label);

            return;
        }

        if ($this->takePrefix($body, self::LETTER_PREFIX, $label)) {
            self::setText($title, $label);
        }
    }

    private function makeParagraph(DOMElement $letter, DOMElement $title, DOMElement $body, string $number): void
    {
        self::rename($letter, 'S_LIT', 'S_ALN');
        self::rename($title, 'S_LIT_TTL', 'S_ALN_TTL');
        self::rename($body, 'S_LIT_BDY', 'S_ALN_BDY');
        self::setText($title, '(' . $number . ')');
    }

    /** A line that opens with a point's or a dash's label, made that container. */
    private function lineToContainer(DOMElement $line, string $prefix, string $class, ?string $shownLabel = null): void
    {
        $document = $line->ownerDocument;

        if ($document === null || ! $this->takePrefix($line, $prefix, $label)) {
            return;
        }

        $container = $document->createElement('span');
        $container->setAttribute('class', $class);
        $title = $document->createElement('span');
        $title->setAttribute('class', $class . '_TTL');
        self::setText($title, $shownLabel ?? $label);
        $body = $document->createElement('span');
        $body->setAttribute('class', $class . '_BDY');

        while ($line->firstChild !== null) {
            $body->appendChild($line->firstChild);
        }

        $container->appendChild($title);
        $container->appendChild($body);
        $line->parentNode?->replaceChild($container, $line);
    }

    /**
     * A table drawn in box characters, as a real one — and inside the provision it follows.
     *
     * A row runs from one rule line to the next; a cell's text, spread over several lines of the
     * drawing, is joined.
     */
    private function boxTableToTable(DOMElement $pre): void
    {
        $document = $pre->ownerDocument;
        $lines = array_map(
            static fn (DOMElement $line): string => $line->textContent,
            $this->byClass('S_PAR', $pre),
        );

        if ($document === null || ! array_filter($lines, static fn (string $line): bool => str_contains($line, self::BOX_CELL))) {
            return;
        }

        $rows = [];
        $pending = [];

        foreach ([...$lines, '└'] as $line) {
            if (preg_match(self::BOX_RULE, $line) === 1) {
                if ($pending !== []) {
                    $rows[] = array_map(static fn (array $parts): string => trim(implode(' ', array_filter($parts))), $pending);
                    $pending = [];
                }

                continue;
            }

            $cells = explode(self::BOX_CELL, trim($line));

            // The drawing's own left and right edges.
            $cells = array_slice($cells, 1, max(0, count($cells) - 2));

            foreach ($cells as $index => $cell) {
                $pending[$index][] = trim((string) preg_replace('/\s+/u', ' ', $cell));
            }
        }

        $table = $document->createElement('table');

        foreach ($rows as $row) {
            $tr = $document->createElement('tr');

            foreach ($row as $cell) {
                $td = $document->createElement('td');
                self::setText($td, $cell);
                $tr->appendChild($td);
            }

            $table->appendChild($tr);
        }

        // Where the drawing stood right after a provision, it belongs to that provision — the
        // sentence introducing it ends "potrivit tabelului următor:".
        $previous = $pre->previousSibling;

        while ($previous !== null && ! $previous instanceof DOMElement) {
            $previous = $previous->previousSibling;
        }

        $owner = $previous instanceof DOMElement ? $this->bodyOf($previous) : null;

        if ($owner === null) {
            $pre->parentNode?->replaceChild($table, $pre);

            return;
        }

        $pre->parentNode?->removeChild($pre);
        $owner->appendChild($table);
    }

    /** The `_BDY` of a provision's container, or null for anything else. */
    private function bodyOf(DOMElement $element): ?DOMElement
    {
        foreach (['S_ALN', 'S_LIT', 'S_PCT', 'S_LIN'] as $class) {
            if (in_array($class, self::classes($element), true)) {
                return $this->part($element, $class . '_BDY');
            }
        }

        return null;
    }

    /**
     * Removes a label from the start of an element's text, if it opens with one.
     *
     * @param-out string $label
     */
    private function takePrefix(DOMElement $element, string $pattern, ?string &$label): bool
    {
        $label = '';
        $first = $this->firstText($element);

        if ($first === null || preg_match($pattern, $first->data, $match) !== 1) {
            return false;
        }

        $label = $match[1];
        $first->data = (string) preg_replace($pattern, '', $first->data, 1);

        return true;
    }

    /** The first text node with anything in it, in reading order. */
    private function firstText(DOMNode $node): ?DOMText
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->data) !== '') {
                    return $child;
                }

                continue;
            }

            $found = $this->firstText($child);

            if ($found !== null || ($child instanceof DOMElement && trim($child->textContent) !== '')) {
                return $found;
            }
        }

        return null;
    }

    private function part(DOMElement $container, string $class): ?DOMElement
    {
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($class, self::classes($child), true)) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<DOMElement> in document order, taken before anything is rewritten */
    private function byClass(string $class, ?DOMNode $within = null): array
    {
        $found = [];

        foreach ($this->xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]", $within) ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $found[] = $element;
            }
        }

        return $found;
    }

    private static function setText(DOMElement $element, string $text): void
    {
        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }

        $element->appendChild(($element->ownerDocument ?? new DOMDocument())->createTextNode($text));
    }

    private static function rename(DOMElement $element, string $from, string $to): void
    {
        $element->setAttribute('class', implode(' ', array_map(
            static fn (string $class): string => $class === $from ? $to : $class,
            self::classes($element),
        )));
    }

    /** @return list<string> */
    private static function classes(DOMElement $element): array
    {
        $class = trim($element->getAttribute('class'));

        return $class === '' ? [] : (preg_split('/\s+/', $class) ?: []);
    }
}
