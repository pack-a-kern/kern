<?php

declare(strict_types=1);

namespace Tests\Feature\Ui\TableFigureTest;

use RuntimeException;

/**
 * A table cell never colours itself with a utility, because the utility cannot win.
 *
 * ## Why this is a test and not a convention
 *
 * `.data-table td` sets `color` and is unlayered; `text-destructive` and `text-success` are Tailwind
 * utilities and sit in `@layer utilities`. Unlayered beats layered whatever the specificity, so a
 * cell written as `<td className="text-destructive">` draws in muted grey and the markup says
 * otherwise. Nothing fails, nothing warns — the amount is simply not red.
 *
 * `.is-in` and `.is-out` exist for this, and say so where they are declared. The transactions screen
 * and the reports screen used them; the statement review and its rebuilt preview used the utility,
 * and every negative amount on both was grey for as long as they had existed.
 *
 * ## What it refuses
 *
 * The utility on the cell itself. Not inside it: an icon or a note *within* a cell is its own
 * element, `.data-table td` does not name it, and the utility applies there normally.
 *
 * @return array<string, string> Keyed by path below `resources/js`.
 */
function sources(): array
{
    $root = dirname(__DIR__, 3) . '/resources/js';
    $found = [];

    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'tsx') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            throw new RuntimeException("Could not read {$file->getPathname()}.");
        }

        $found[substr($file->getPathname(), strlen($root) + 1)] = $contents;
    }

    return $found;
}

it('never puts a colour utility on a table cell', function (): void {
    $offenders = [];

    foreach (sources() as $name => $contents) {
        // The opening tag only: everything from `<td` or `<th` up to the `>` that closes it.
        preg_match_all('/<(?:td|th)\b[^>]*>/s', $contents, $tags, PREG_OFFSET_CAPTURE);

        foreach ($tags[0] as [$tag, $offset]) {
            foreach (['text-destructive', 'text-success'] as $utility) {
                if (str_contains($tag, $utility)) {
                    $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                    $offenders[] = "{$name}:{$line} carries {$utility}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('finds the cells at all, so a passing run means something', function (): void {
    $cells = 0;

    foreach (sources() as $contents) {
        $cells += preg_match_all('/<(?:td|th)\b/', $contents);
    }

    expect($cells)->toBeGreaterThan(100);
});
