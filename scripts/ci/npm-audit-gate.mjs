#!/usr/bin/env node
// CI gate over `npm audit`: fail the build on any high or critical advisory, EXCEPT a small,
// documented allowlist of advisories that are dev-tooling-only and have no upstream fix. Each entry
// says why it is tolerated and is removed the moment upstream ships a patched release.
//
// Run from an app directory (the CI job's working-directory), with node_modules already installed:
//   node ../../scripts/ci/npm-audit-gate.mjs
//
// It replaces a bare `npm audit --audit-level=high` (DEPENDENCY-POLICY.md §3) so a dev-only,
// unreachable, unpatched advisory does not red the whole pipeline while still failing on anything
// real or new. Written in Node, not jq, so the one script runs unchanged in both JS jobs — the
// apps/api runner and the Playwright container, which carries no jq.

import { spawnSync } from 'node:child_process';

/**
 * Advisories tolerated at high/critical severity, keyed by GHSA id (matched against each advisory's
 * URL). Keep this empty whenever upstream lets you — an entry here is a promise to come back and
 * remove it. A new high/critical advisory that is NOT listed here still fails the build.
 */
const ALLOWLIST = {
    'GHSA-fc5q-39xc-vjpf': {
        package: 'picomatch',
        reason:
            'ReDoS reachable only through a glob pattern. picomatch is pulled in only by dev tooling ' +
            '(eslint / typescript-eslint / tinyglobby / fdir, and Vite), never a shipped runtime, and ' +
            'the only patterns it is ever given are the globs we write in lint and build config — never ' +
            'user or attacker input.',
        noFix: 'Unpatched upstream: vulnerable range <= 4.0.7, no fixed release (checked 2026-11-17).',
    },
};

const result = spawnSync('npm', ['audit', '--json'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });

if (result.error) {
    console.error(`npm audit could not be run: ${result.error.message}`);
    process.exit(2);
}

let report;
try {
    report = JSON.parse(result.stdout);
} catch {
    console.error('npm audit did not return parseable JSON:');
    console.error((result.stdout || result.stderr || '').slice(0, 2000));
    process.exit(2);
}

const allowIds = Object.keys(ALLOWLIST);
const isAllowlisted = (url) => typeof url === 'string' && allowIds.some((id) => url.includes(id));

// npm audit v2 shape: `vulnerabilities{}`, each with a `via[]` holding either a parent package name
// (a string) or the advisory itself (an object `{ name, severity, url, source }`). The objects are
// the real advisories; collect the high/critical ones, deduped by URL.
const advisories = new Map();
for (const vuln of Object.values(report.vulnerabilities ?? {})) {
    for (const via of vuln.via ?? []) {
        if (via && typeof via === 'object' && (via.severity === 'high' || via.severity === 'critical')) {
            const url = via.url ?? `${via.name ?? 'unknown'}#${via.source ?? ''}`;
            advisories.set(url, { name: via.name ?? 'unknown', severity: via.severity, url });
        }
    }
}

const all = [...advisories.values()];
const blocking = all.filter((a) => !isAllowlisted(a.url));

for (const a of all.filter((a) => isAllowlisted(a.url))) {
    console.log(`allowlisted: ${a.severity}  ${a.name}  ${a.url}`);
}

if (blocking.length > 0) {
    console.error(`\nnpm audit: ${blocking.length} high/critical advisory(ies) outside the allowlist:`);
    for (const a of blocking) {
        console.error(`  ${a.severity}  ${a.name}  ${a.url}`);
    }
    console.error('\nFix them; add an allowlist entry only for a dev-only, unreachable, unpatched advisory.');
    process.exit(1);
}

console.log('npm audit: no high/critical advisories outside the allowlist.');
process.exit(0);
