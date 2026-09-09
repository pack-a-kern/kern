<?php

declare(strict_types=1);

use App\Domain\TaxEngine\Contracts\TaxRuleContract;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Support\TaxEngineRulePurity\RulePuritySourceScanner;

/**
 * `docs/AGENTS.md` §11.2/ADR 0004 — "a rule that reaches for the database should fail the suite."
 * This test, not a code-review convention, is that enforcement: it discovers every class under
 * `app/Domain/TaxEngine` that implements `TaxRuleContract` — however many exist, including zero,
 * as of this milestone (`docs/AGENTS.md` rule 3 — no tax rule ships without an approved source, so
 * no concrete rule is shipped here; see `docs/handoff/020-tax-engine.md`) — and scans each one's
 * own source file. A future task that adds a real, concrete rule under
 * `app/Domain/TaxEngine/Rules/**` is checked by this exact test with no changes to it.
 */
it('has zero purity violations in every TaxRuleContract implementation shipped under app/Domain/TaxEngine', function (): void {
    $root = app_path('Domain/TaxEngine');

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() === 'php') {
            require_once $file->getPathname();
        }
    }

    $implementors = array_filter(
        get_declared_classes(),
        static fn (string $class): bool => is_a($class, TaxRuleContract::class, true),
    );

    $scanner = new RulePuritySourceScanner();
    $violationsByClass = [];

    foreach ($implementors as $class) {
        $reflection = new ReflectionClass($class);
        $path = $reflection->getFileName();

        if ($path === false) {
            continue;
        }

        $violations = $scanner->scanFile($path);

        if ($violations !== []) {
            $violationsByClass[$class] = $violations;
        }
    }

    expect($violationsByClass)->toBe([]);
});

it('would catch a violation in a shipped rule, proven against the test-only synthetic fixture', function (): void {
    // Not under app/Domain/TaxEngine (the test above's scope) — this proves the scanner itself
    // catches a real violation shape, complementing tests/Unit/Domain/TaxEngine/
    // RulePuritySourceScannerTest.php with the exact scan path the architecture test above uses.
    $violations = (new RulePuritySourceScanner())->scanFile(
        base_path('tests/Fixtures/TaxEngine/Purity/ImpureDatabaseRule.php'),
    );

    expect($violations)->not->toBe([]);
});
