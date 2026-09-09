import path from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
// `vitest/config` re-exports Vite's own `defineConfig` merged with the `test` option's types —
// this file stays the single source of truth Vite *and* Vitest both read (no separate
// `vitest.config.ts`), per `docs/DEPENDENCY-POLICY.md` §1 "prefer the existing tool" over adding a
// second config file/dependency.
import { defineConfig } from 'vitest/config';
import tailwindcss from '@tailwindcss/vite';

const dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            // Mirrors the `@/*` -> `resources/js/*` mapping in `tsconfig.json`. shadcn/ui's CLI
            // generates imports as `@/components/...`, `@/lib/utils`, etc.; this is what makes
            // those imports resolve at build/dev/test time (TypeScript's `paths` only affects
            // type-checking, not the actual module resolution Vite/Vitest perform).
            '@': path.resolve(dirname, 'resources/js'),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['./resources/js/test/setup.ts'],
        css: false,
    },
});
