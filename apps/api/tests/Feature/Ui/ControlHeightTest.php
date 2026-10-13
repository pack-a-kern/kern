<?php

declare(strict_types=1);

namespace Tests\Feature\Ui\ControlHeightTest;

use RuntimeException;

/**
 * Every control is the same height, and nothing restates that height by hand.
 *
 * ## Why this is a test and not a convention
 *
 * Because the convention kept losing. `.ctl` is the one class a select, an input and a button all
 * wear, so a form's controls line up without anybody arranging them — and the moment a rule writes
 * `height: 2rem` instead of reading the token, that rule agrees with `.ctl` only by coincidence.
 * Setting `--ctl-h` to something else then moves half the interface.
 *
 * It had already happened three times over, each found by hand rather than by anything: `.ctl-multi`
 * held its own `2rem` and stayed behind when the control height changed; `.ctl-action` and
 * `.ctl-action-link` sat at a flat `30px`, a number that was right only while controls were 32.
 *
 * ## What it allows
 *
 * `var(--ctl-h)`, and arithmetic on it — `.ctl-action` is `calc(var(--ctl-h) - 4px)` because it sits
 * *inside* a control and should stay inset by the same amount whatever that control becomes. What it
 * refuses is a literal: `2rem`, `30px`, `34px`.
 *
 * ## What it does not cover
 *
 * Rules outside the `.ctl` family. A sidebar item, a square pager button and a table row's mark all
 * have heights of their own, deliberately, and each says so in a comment where it is declared. This
 * test is about the family that has to agree, not about every number in the stylesheet.
 */
function stylesheet(): string
{
    $path = dirname(__DIR__, 3) . '/resources/css/app.css';
    $css = file_get_contents($path);

    return $css === false ? throw new RuntimeException("Could not read {$path}.") : $css;
}

/**
 * Every `.ctl…` rule, as selector => declaration block.
 *
 * @return array<string, string>
 */
function controlRules(): array
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', stylesheet(), $matches, PREG_SET_ORDER);

    $rules = [];

    foreach ($matches as $match) {
        $selector = trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');

        // Comments sit between rules and land in the captured selector; strip them so the name a
        // failure prints is the selector rather than the paragraph above it.
        $selector = trim((string) preg_replace('/\/\*.*?\*\//s', '', $selector));

        if ($selector !== '' && str_contains($selector, '.ctl')) {
            $rules[$selector] = $match[2];
        }
    }

    return $rules;
}

it('finds the control rules at all, so a passing run means something', function (): void {
    // Without this, a stylesheet that moved or a regex that stopped matching would leave every
    // assertion below iterating an empty list and reporting success.
    expect(array_keys(controlRules()))->toContain('.ctl');
});

it('states every control height as the shared token, never as a literal', function (): void {
    $literals = [];

    foreach (controlRules() as $selector => $block) {
        preg_match_all('/(?:^|\s|;)(min-height|max-height|height)\s*:\s*([^;]+);/', $block, $found, PREG_SET_ORDER);

        foreach ($found as $declaration) {
            $value = trim($declaration[2]);

            /*
             * Allowed: either token, bare or inside a `calc`; `auto`, which is not a height anybody
             * has to keep in step; and `100%`, which tracks a parent that is already on a token —
             * `.input-group .ctl` fills the group around it.
             *
             * There are two tokens because there are two control sizes: `--ctl-h` is the row's own
             * height, and `--ctl-h-sm` the shorter one an action inside a card wears. `--ctl-h-sm`
             * is itself a `calc` off `--ctl-h`, so there is still one number.
             */
            if (
                $value === 'auto'
                || $value === '100%'
                || str_contains($value, 'var(--ctl-h)')
                || str_contains($value, 'var(--ctl-h-sm)')
            ) {
                continue;
            }

            // `.ctl-icon` is the one literal that stays, and it belongs to a different scale rather
            // than being an oversight: an icon is sized to its glyph and its stroke, so the same
            // magnifier belongs in a short field and a tall one. It sits on the interface's icon
            // scale — 12, 14, 16, 20, 24 — where every other icon does. Named here rather than
            // matched by a pattern, so a second exception is a decision somebody writes down.
            if (str_starts_with($selector, '.ctl-icon')) {
                continue;
            }

            $literals[] = "{$selector} { {$declaration[1]}: {$value} }";
        }
    }

    expect($literals)->toBe([]);
});

it('declares the token once, so there is one number to change', function (): void {
    expect(substr_count(stylesheet(), '--ctl-h:'))->toBe(1);
});

it('derives the short control height from the tall one rather than restating it', function (): void {
    // The two sizes are 34px and 30px. Written as two numbers they drift; written as a `calc` off
    // the first, raising the row raises both — which is what made this worth a token at all.
    expect(stylesheet())->toContain('--ctl-h-sm: calc(var(--ctl-h) - 4px)')
        ->and(substr_count(stylesheet(), '--ctl-h-sm:'))->toBe(1);
});
