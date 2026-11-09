import { describe, expect, it } from 'vitest';

import { parsePortalSearch } from '../legislation';

describe('parsePortalSearch', () => {
    it('reads a search as the server answers it, with its acts', () => {
        const search = parsePortalSearch({
            id: 's-1',
            status: 'incomplete',
            pages_read: 1,
            failure: 'portal_search_refused',
            poll_after_ms: null,
            acts: [
                {
                    type: 'ORDIN',
                    type_label: 'ORDIN',
                    number: '1528',
                    year: 2026,
                    title: 'ORDIN nr. 1.528 din 5 noiembrie 2026',
                    issuer: 'Ministerul Finanțelor',
                    in_force_from: '2026-11-06',
                    portal_id: '316083',
                    url: 'http://legislatie.just.ro/Public/DetaliiDocument/316083',
                    importable: true,
                    law_id: null,
                },
            ],
        });

        expect(search).toMatchObject({
            id: 's-1',
            status: 'incomplete',
            pagesRead: 1,
            failure: 'portal_search_refused',
            pollAfterMs: null,
        });
        expect(search.acts).toHaveLength(1);
        expect(search.acts[0]).toMatchObject({ number: '1528', portalId: '316083', importable: true, lawId: null });
    });

    it('refuses a status it does not know, rather than show a search as something it is not', () => {
        expect(() => parsePortalSearch({ id: 's-1', status: 'paused', pages_read: 0, acts: [] })).toThrow();
    });
});
