<?php

declare(strict_types=1);

namespace Kern\Platform\Logging;

/**
 * What must never reach a log file, and what only this installation knows about.
 *
 * {@see SensitiveDataProcessor} carries the shapes every application has — a password, a token, an
 * `Authorization` header, a JWT, an IBAN — and asks here for the rest. A CNP is thirteen digits
 * starting 1–8 and a CUI is a company's fiscal code; both are Romanian, and a package that knew them
 * would be a package that knows what a taxpayer is. `kern/tax-romania` declares them in its provider.
 *
 * A registry rather than configuration, for the reason two packages always find: a package's config
 * file is merged one level deep, so whichever of them registered second would replace the other's
 * list instead of adding to it.
 *
 * Read on every log record rather than captured when the processor is built, so a package whose
 * provider boots after the log channel was first resolved still has its words honoured.
 *
 * **The risk this shape carries, stated plainly:** a package that forgets to declare its own
 * identifiers leaks them, silently, until somebody reads a log file. The answer is that the test
 * belongs to the package that owns the words — see `kern/tax-romania`'s own suite, which fails if
 * the declaration below is removed.
 */
final class Redactions
{
    /**
     * Key names — normalised to lower case with non-alphanumerics stripped — whose value is redacted
     * whatever shape it has.
     *
     * @var list<string>
     */
    private array $keys = [];

    /** @var list<string> */
    private array $patterns = [];

    /** @var list<array{pattern: string, replacement: string}> */
    private array $replacements = [];

    /** Key names whose value is always dropped, whatever it looks like. */
    public function keys(string ...$keys): void
    {
        foreach ($keys as $key) {
            $normalised = preg_replace('/[^a-z0-9]/', '', mb_strtolower($key)) ?? '';

            if ($normalised !== '' && ! in_array($normalised, $this->keys, true)) {
                $this->keys[] = $normalised;
            }
        }
    }

    /** A shape to redact wherever it appears in free text — an identifier's own format. */
    public function pattern(string $pattern): void
    {
        if (! in_array($pattern, $this->patterns, true)) {
            $this->patterns[] = $pattern;
        }
    }

    /**
     * The same, where part of the match must survive — `OTP: 123456` becoming `OTP: [REDACTED]`
     * rather than disappearing, so a log still says what was asked for.
     */
    public function replacement(string $pattern, string $replacement): void
    {
        foreach ($this->replacements as $existing) {
            if ($existing['pattern'] === $pattern) {
                return;
            }
        }

        $this->replacements[] = ['pattern' => $pattern, 'replacement' => $replacement];
    }

    /** @return list<string> */
    public function declaredKeys(): array
    {
        return $this->keys;
    }

    /** @return list<string> */
    public function declaredPatterns(): array
    {
        return $this->patterns;
    }

    /** @return list<array{pattern: string, replacement: string}> */
    public function declaredReplacements(): array
    {
        return $this->replacements;
    }
}
