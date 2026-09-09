import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DuplicateCnpError, listSubjects, registerPfSubject } from './subjectsApi';

function setCsrfMeta(token: string): void {
    document.head.querySelectorAll('meta[name="csrf-token"]').forEach((el) => {
        el.remove();
    });
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', token);
    document.head.append(meta);
}

beforeEach(() => {
    setCsrfMeta('test-token');
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('listSubjects', () => {
    it('parses the cursor-paginated envelope', async () => {
        const body = JSON.stringify({
            data: [{ id: 'sub-1', type: 'PF', display_name: 'Ion Popescu', status: 'ACTIVE', role: 'OWNER' }],
            next_cursor: null,
            prev_cursor: null,
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 200 })));

        const page = await listSubjects();
        expect(page.data).toEqual([
            { id: 'sub-1', type: 'PF', displayName: 'Ion Popescu', status: 'ACTIVE', role: 'OWNER' },
        ]);
    });

    it('appends the cursor query parameter when paging', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ data: [], next_cursor: null, prev_cursor: null }), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);

        await listSubjects('cursor-abc');
        const url = fetchMock.mock.calls[0]?.[0] as string;
        expect(url).toContain('cursor=cursor-abc');
    });
});

describe('registerPfSubject', () => {
    it('sends snake_case fields and parses the created id', async () => {
        const fetchMock = vi.fn<typeof fetch>();
        fetchMock.mockResolvedValue(new Response(JSON.stringify({ id: 'sub-new' }), { status: 201 }));
        vi.stubGlobal('fetch', fetchMock);

        const result = await registerPfSubject({
            displayName: 'Ion Popescu',
            firstName: 'Ion',
            lastName: 'Popescu',
            cnp: '1234567890123',
            birthDate: '1990-01-01',
        });

        expect(result).toEqual({ id: 'sub-new' });
        const sentBody = JSON.parse(fetchMock.mock.calls[0]?.[1]?.body as string) as Record<string, unknown>;
        expect(sentBody).toMatchObject({
            display_name: 'Ion Popescu',
            first_name: 'Ion',
            last_name: 'Popescu',
            cnp: '1234567890123',
            birth_date: '1990-01-01',
            county_code: null,
        });
    });

    it('translates a 422 DUPLICATE_CNP response into DuplicateCnpError', async () => {
        const body = JSON.stringify({ message: 'CNP already registered.', reason: 'DUPLICATE_CNP' });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 422 })));

        await expect(
            registerPfSubject({
                displayName: 'Ion Popescu',
                firstName: 'Ion',
                lastName: 'Popescu',
                cnp: '1234567890123',
                birthDate: '1990-01-01',
            }),
        ).rejects.toBeInstanceOf(DuplicateCnpError);
    });

    it('does not wrap an unrelated 422 as DuplicateCnpError', async () => {
        const body = JSON.stringify({ message: 'Validation failed.', errors: { cnp: ['Invalid format'] } });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(body, { status: 422 })));

        await expect(
            registerPfSubject({
                displayName: 'Ion Popescu',
                firstName: 'Ion',
                lastName: 'Popescu',
                cnp: 'not-a-cnp',
                birthDate: '1990-01-01',
            }),
        ).rejects.not.toBeInstanceOf(DuplicateCnpError);
    });
});
