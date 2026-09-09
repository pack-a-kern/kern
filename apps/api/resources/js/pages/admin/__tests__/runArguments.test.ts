import { describe, expect, it } from 'vitest';

import type { TaskArgument } from '../../../types/operations';

/**
 * The run dialog builds its payload from the task's own argument declaration.
 *
 * Every argument used to be pushed through `Number()`. That is correct for the FX backfill's year
 * and silently destructive for everything else: `Number('LEGISLATIE')` is `NaN`, and
 * `JSON.stringify` writes `NaN` as `null`, so the server received three null fields and reported
 * them as missing. TypeScript could not catch it — `Record<string, number>` is assignable to
 * `Record<string, number | string>`, so the call site still compiled.
 */
function buildArguments(args: TaskArgument[], entered: Record<string, string>): Record<string, number | string> {
    const built: Record<string, number | string> = {};

    for (const argument of args) {
        const raw = entered[argument.name] ?? '';
        built[argument.name] = argument.type === 'integer' ? Number(raw) : raw;
    }

    return built;
}

function argument(name: string, type: TaskArgument['type']): TaskArgument {
    return { name, type, min: null, max: null, options: [], required: true };
}

describe('run dialog payload', () => {
    it('keeps choice and url arguments as strings', () => {
        const built = buildArguments(
            [argument('source', 'choice'), argument('url', 'url'), argument('type', 'choice')],
            {
                source: 'LEGISLATIE',
                url: 'https://legislatie.just.ro/Public/DetaliiDocument/171282',
                type: 'LEGE',
            },
        );

        expect(built).toEqual({
            source: 'LEGISLATIE',
            url: 'https://legislatie.just.ro/Public/DetaliiDocument/171282',
            type: 'LEGE',
        });

        // The regression itself: nothing may serialise to null.
        expect(JSON.parse(JSON.stringify(built))).toEqual(built);
    });

    it('still coerces an integer argument', () => {
        expect(buildArguments([argument('year', 'integer')], { year: '2019' })).toEqual({ year: 2019 });
    });
});
