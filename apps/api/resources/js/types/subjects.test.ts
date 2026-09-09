import { describe, expect, it } from 'vitest';
import { ValidationError } from '../lib/validate';
import { parseRegisteredSubjectId, parseSubjectSummary, parseSubjectsPage } from './subjects';

describe('parseSubjectSummary', () => {
    it('parses a ListSubjectsController row', () => {
        expect(
            parseSubjectSummary(
                JSON.stringify({
                    id: 'sub-1',
                    type: 'PF',
                    display_name: 'Ion Popescu',
                    status: 'ACTIVE',
                    role: 'OWNER',
                }),
            ),
        ).toEqual({ id: 'sub-1', type: 'PF', displayName: 'Ion Popescu', status: 'ACTIVE', role: 'OWNER' });
    });

    it('rejects an unknown subject type', () => {
        const bad = JSON.stringify({
            id: 'sub-1',
            type: 'NOT_A_TYPE',
            display_name: 'Ion Popescu',
            status: 'ACTIVE',
            role: 'OWNER',
        });

        expect(() => parseSubjectSummary(bad)).toThrow(ValidationError);
    });
});

describe('parseSubjectsPage', () => {
    it('parses the GET /api/v1/subjects cursor envelope', () => {
        const body = JSON.stringify({
            data: [{ id: 'sub-1', type: 'PF', display_name: 'Ion Popescu', status: 'ACTIVE', role: 'OWNER' }],
            path: 'http://example.test/api/v1/subjects',
            per_page: 20,
            next_cursor: 'abc',
            next_page_url: 'http://example.test/api/v1/subjects?cursor=abc',
            prev_cursor: null,
            prev_page_url: null,
        });

        const page = parseSubjectsPage(body);

        expect(page.nextCursor).toBe('abc');
        expect(page.prevCursor).toBeNull();
        expect(page.data).toEqual([
            { id: 'sub-1', type: 'PF', displayName: 'Ion Popescu', status: 'ACTIVE', role: 'OWNER' },
        ]);
    });

    it('parses an empty list (a brand-new user with no subjects yet)', () => {
        const body = JSON.stringify({
            data: [],
            path: 'http://example.test/api/v1/subjects',
            per_page: 20,
            next_cursor: null,
            prev_cursor: null,
        });

        expect(parseSubjectsPage(body).data).toEqual([]);
    });
});

describe('parseRegisteredSubjectId', () => {
    it('parses the POST /api/v1/subjects/pf 201 body', () => {
        expect(parseRegisteredSubjectId(JSON.stringify({ id: 'sub-new' }))).toEqual({ id: 'sub-new' });
    });
});
