<?php

declare(strict_types=1);

namespace Tests\Feature\Ui\StylesheetCollisionTest;

use RuntimeException;

/**
 * No two rules in the same context claim the same selector and the same property.
 *
 * ## Why this is a test and not a convention
 *
 * Because reusing a class name that is already taken looks like nothing at the point of writing, and
 * like a broken screen several rules later. It has happened three times in this stylesheet:
 *
 * - `.ctl-icon` was taken by the icon *inside* a field (`position: absolute; pointer-events: none`),
 *   and a second one was written for square icon buttons. The later rule won, and every row button
 *   in every table became unclickable and left the row.
 * - `.metric-label` and `.metric-value` already existed; a second pair was added for a new card and
 *   silently changed the type size of every metric in the application.
 * - `.step` was taken by the 16px numbered marker in instruction lists; a second one was written for
 *   the import wizard, and the wizard's labels fell underneath their dots because the live rule made
 *   each step a 16px `inline-grid` box.
 *
 * Each was found by somebody looking at a broken page and saying so, which is the slowest way to
 * find it and the only way available until now.
 *
 * ## What it refuses, and what it allows
 *
 * It refuses two blocks with the same selector, in the same at-rule context, that both set the same
 * property — one of them is being discarded, and which one depends on their order in the file.
 *
 * It allows a second block that sets *different* properties: `.fact-label` states its typography in
 * one place and its layout in another, and both apply. It allows the same selector under different
 * at-rules, which is how `.filter-grid` takes a different column count at each breakpoint, and it
 * allows `:root` restating every token under `prefers-color-scheme: dark`.
 *
 * ## What it does not cover
 *
 * One rule beating another through a *different* selector — `.data-table td` over `.text-destructive`
 * is a collision too, and an intended one. {@see \Tests\Feature\Ui\TableFigureTest} covers the case
 * of that kind which keeps being written by accident.
 */
function stylesheet(): string
{
    $path = dirname(__DIR__, 3) . '/resources/css/app.css';
    $css = file_get_contents($path);

    return $css === false ? throw new RuntimeException("Could not read {$path}.") : $css;
}

/** One `{ … }` the walker has opened and not yet closed. */
final class Block
{
    public function __construct(
        public string $head,
        public int $line,
        public string $body = '',
    ) {
    }

    /** `@media`, `@layer` and friends hold rules rather than declarations. */
    public function isAtRule(): bool
    {
        return str_starts_with($this->head, '@');
    }
}

/**
 * The selectors in one group, split at the commas that separate them.
 *
 * Depth-aware, because `:not(a, b)` and `:is(a, b)` hold commas that join rather than separate —
 * splitting on every comma turned one `button:not([aria-disabled='true'], [data-disabled])` into two
 * halves of a selector, each of which then looked like a rule of its own.
 *
 * @return list<string>
 */
function selectors(string $group): array
{
    $found = [];
    $buffer = '';
    $depth = 0;

    foreach (str_split($group) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        } elseif ($character === ',' && $depth === 0) {
            $found[] = trim($buffer);
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    $found[] = trim($buffer);

    return array_values(array_filter($found, static fn (string $selector): bool => $selector !== ''));
}

/**
 * Every rule in the file, as `[context, selector, properties, line]`, one entry per selector.
 *
 * Hand-walked rather than regexed: the context a rule sits in is the stack of at-rules above it, and
 * that is what decides whether two rules with one selector are the same rule twice or one rule at
 * two breakpoints.
 *
 * @return list<array{context: string, selector: string, properties: list<string>, line: int}>
 */
function rules(string $css): array
{
    // Comments hold braces and colons; blanking them keeps the line count intact for the message.
    $css = (string) preg_replace_callback(
        '!/\*.*?\*/!s',
        static fn (array $m): string => str_repeat("\n", substr_count((string) $m[0], "\n")),
        $css,
    );

    $rules = [];

    /** @var list<Block> $stack */
    $stack = [];
    $buffer = '';
    $line = 1;

    foreach (str_split($css) as $character) {
        if ($character === "\n") {
            $line++;
        }

        if ($character === '{') {
            $stack[] = new Block(trim((string) preg_replace('/\s+/', ' ', $buffer)), $line);
            $buffer = '';

            continue;
        }

        if ($character === '}') {
            $open = array_pop($stack);

            if ($open !== null && ! $open->isAtRule()) {
                $context = [];

                foreach ($stack as $entry) {
                    if ($entry->isAtRule()) {
                        $context[] = $entry->head;
                    }
                }

                $properties = [];

                foreach (explode(';', $open->body) as $declaration) {
                    $name = trim((string) strstr($declaration, ':', true));

                    if ($name !== '') {
                        $properties[] = $name;
                    }
                }

                /*
                 * One entry per selector in a group, not one per block: `a, b { }` declares two
                 * rules, and a later block claiming only `b` collides with half of it. Keeping the
                 * group whole would compare the string "a, b" against "b" and find nothing.
                 */
                foreach (selectors($open->head) as $selector) {
                    $rules[] = [
                        'context' => implode(' ', $context),
                        'selector' => $selector,
                        'properties' => $properties,
                        'line' => $open->line,
                    ];
                }
            }

            $buffer = '';

            continue;
        }

        $buffer .= $character;

        // Only a rule's own declarations count — a nested block's belong to the nested rule.
        $depth = count($stack);

        if ($depth > 0 && ! $stack[$depth - 1]->isAtRule()) {
            $stack[$depth - 1]->body .= $character;
        }
    }

    return $rules;
}

it('never declares one selector twice over the same property', function (): void {
    $byKey = [];

    foreach (rules(stylesheet()) as $rule) {
        $byKey[$rule['context'] . '||' . $rule['selector']][] = $rule;
    }

    $collisions = [];

    foreach ($byKey as $group) {
        if (count($group) < 2) {
            continue;
        }

        foreach ($group as $index => $rule) {
            foreach (array_slice($group, $index + 1) as $later) {
                $shared = array_intersect($rule['properties'], $later['properties']);

                if ($shared !== []) {
                    $collisions[] = sprintf(
                        '%s at line %d and line %d both set: %s',
                        $rule['selector'],
                        $rule['line'],
                        $later['line'],
                        implode(', ', $shared),
                    );
                }
            }
        }
    }

    expect($collisions)->toBe([]);
});

it('finds the rules at all, so a passing run means something', function (): void {
    $rules = rules(stylesheet());

    expect(count($rules))->toBeGreaterThan(200)
        ->and(array_column($rules, 'selector'))->toContain('.data-table td.col-figure');
});
