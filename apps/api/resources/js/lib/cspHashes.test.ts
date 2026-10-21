import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

/*
 * config/csp.php allows Sonner's stylesheet by its SHA-256, because Sonner 2.0.8 inserts it with no way
 * to carry a nonce. A hash allows that exact text and nothing else — so a Sonner whose stylesheet
 * changed would be blocked in every browser the moment the policy is enforced, and nothing would say
 * why. This recomputes it from the installed package, so an upgrade fails here instead.
 */
// Vitest runs from the application root (apps/api), where both files live.
const root = (path: string): string => resolve(process.cwd(), path);

function sha256(text: string): string {
    return `'sha256-${createHash('sha256').update(text, 'utf8').digest('base64')}'`;
}

describe('the Content-Security-Policy hashes', () => {
    const policy = readFileSync(root('config/csp.php'), 'utf8');

    it('allows the stylesheet the installed Sonner inserts, exactly', () => {
        const source = readFileSync(root('node_modules/sonner/dist/index.mjs'), 'utf8');
        const literal = /__insertCSS\(("(?:[^"\\]|\\.)*")\)/.exec(source)?.[1];

        expect(
            literal,
            'Sonner no longer inserts its stylesheet through __insertCSS — read how it does now',
        ).toBeDefined();

        expect(policy).toContain(sha256(JSON.parse(literal ?? '""') as string));
    });

    it('allows the empty element Sonner inserts it into first', () => {
        expect(policy).toContain(sha256(''));
    });
});
