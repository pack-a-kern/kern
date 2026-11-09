<?php

declare(strict_types=1);

namespace Kern\Investments\Parsing\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * A page from a source, parsed as a browser parses it (HTML5, `Dom\HTMLDocument`), and its text read
 * the one safe way: `textContent` only, so markup in a cell is never kept or run — a `<script>` in a
 * company's name is text, and the length bound below cuts it.
 */
final class HtmlPage
{
    /** Longer than any name, symbol or figure a source shows; anything past it is not one. */
    public const int MAX_TEXT = 300;

    public static function load(string $html): HTMLDocument
    {
        // NOERROR: a page's own sloppy markup is the norm, not a failure; what must be there is checked
        // by what reads it.
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    /** A node's text: whitespace collapsed (no-break spaces too), control characters dropped, bounded. */
    public static function text(?Node $node): string
    {
        $text = preg_replace(['/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '/[\s\x{00A0}]+/u'], ['', ' '], $node->textContent ?? '') ?? '';

        return mb_substr(trim($text), 0, self::MAX_TEXT);
    }

    /** The first element after a heading with this text — the way a section with no column headers is found. */
    public static function tableUnder(HTMLDocument $page, string $heading): ?Element
    {
        foreach ($page->querySelectorAll('h1, h2, h3') as $candidate) {
            if (self::text($candidate) === $heading) {
                return $candidate->parentElement?->querySelector('table');
            }
        }

        return null;
    }
}
