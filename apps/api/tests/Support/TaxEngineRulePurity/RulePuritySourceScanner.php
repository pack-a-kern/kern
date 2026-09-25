<?php

declare(strict_types=1);

namespace Tests\Support\TaxEngineRulePurity;

use RuntimeException;

/**
 * Enforces `docs/AGENTS.md` §11.2/ADR 0004's purity requirement on
 * `Kern\TaxRomania\TaxEngine\Contracts\TaxRuleContract` implementations **with a test, not just a
 * convention** — a lexical source scan (via PHP's own tokenizer, so it never matches inside a
 * string literal or a comment) for wall-clock reads, randomness, environment reads, and
 * database/HTTP/cache access.
 *
 * Deliberately test-only code (`tests/Support/**`, not `packages/tax-romania/src/TaxEngine/**`): its only
 * consumer is this suite's own CI gate
 * (`tests/Feature/TaxRomania/TaxEngine/RulePurityArchitectureTest.php`), so shipping it as production
 * code would be exactly the "abstraction with a single consumer" `docs/CODE-QUALITY.md` §2
 * forbids.
 *
 * This is a heuristic, not a formal proof: a sufficiently adversarial rule could still smuggle in
 * an impure call this scanner does not recognise (e.g. through a dynamically-built function
 * name). It catches every direct, literal use of every construct named in
 * `TaxRuleContract`'s own docblock, which is what a rule author would actually write.
 */
final class RulePuritySourceScanner
{
    /**
     * Bare identifiers (function names or short class names used as `Foo::...`) that must never
     * appear in a `TaxRuleContract` implementation's source.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_IDENTIFIERS = [
        // Wall clock.
        'now', 'date', 'gmdate', 'time', 'mktime', 'gmmktime', 'strtotime', 'microtime', 'Carbon', 'CarbonImmutable',
        // Randomness.
        'rand', 'mt_rand', 'random_int', 'random_bytes', 'uniqid', 'lcg_value',
        // Environment.
        'env', 'getenv', 'ini_get',
        // Network / filesystem I/O.
        'curl_init', 'curl_exec', 'file_get_contents', 'fopen', 'fsockopen', 'stream_socket_client', 'Http',
        // Database / cache / Eloquent.
        'DB', 'Cache', 'Redis', 'Model', 'Eloquent', 'Storage',
    ];

    /**
     * @return list<string> human-readable violation descriptions; empty means the file is clean.
     */
    public function scanFile(string $filePath): array
    {
        $source = file_get_contents($filePath);

        if ($source === false) {
            throw new RuntimeException(sprintf('Could not read "%s".', $filePath));
        }

        $tokens = token_get_all($source);
        $violations = [];

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_STRING && in_array($text, self::FORBIDDEN_IDENTIFIERS, true)) {
                $violations[] = sprintf('line %d: forbidden identifier "%s"', $line, $text);
            }
        }

        foreach ($this->findNoArgumentDateTimeConstructions($tokens) as $violation) {
            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * `new DateTime()`/`new DateTimeImmutable()` with **no** constructor argument reads the
     * current wall-clock time — the same violation as calling `now()`. The same classes
     * constructed *with* an argument (e.g. `new DateTimeImmutable($input->asOf->format(...))`)
     * are pure and must not be flagged.
     *
     * @param list<mixed> $tokens
     *
     * @return list<string>
     */
    private function findNoArgumentDateTimeConstructions(array $tokens): array
    {
        $violations = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $classIndex = $this->nextSignificant($tokens, $i + 1);

            if ($classIndex === null || ! is_array($tokens[$classIndex])) {
                continue;
            }

            [$classId, $className, $classLine] = $tokens[$classIndex];
            assert(is_string($className));
            assert(is_int($classLine));

            if ($classId !== T_STRING || ! in_array($className, ['DateTime', 'DateTimeImmutable'], true)) {
                continue;
            }

            $openParenIndex = $this->nextSignificant($tokens, $classIndex + 1);

            if ($openParenIndex === null || $tokens[$openParenIndex] !== '(') {
                continue;
            }

            $closeParenIndex = $this->nextSignificant($tokens, $openParenIndex + 1);

            if ($closeParenIndex !== null && $tokens[$closeParenIndex] === ')') {
                $violations[] = sprintf('line %d: "new %s()" with no argument reads the current time', $classLine, $className);
            }
        }

        return $violations;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
