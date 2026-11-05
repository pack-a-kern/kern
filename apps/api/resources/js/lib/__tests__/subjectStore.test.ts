import { afterEach, describe, expect, it } from 'vitest';
import { adoptSubjectFromLink, getActiveSubjectId, withoutSubjectLink } from '../subjectStore';

afterEach(() => {
    window.localStorage.clear();
    window.history.replaceState(null, '', '/');
});

describe('a link that names its taxpayer', () => {
    it('makes that taxpayer the choice when the page loads', () => {
        window.history.replaceState(null, '', '/wallet/accounts?subject=s-2');

        adoptSubjectFromLink();

        expect(getActiveSubjectId()).toBe('s-2');
    });

    it('leaves the choice alone when the link names none', () => {
        window.localStorage.setItem('kern.activeSubjectId', 's-1');
        window.history.replaceState(null, '', '/wallet/accounts?import=i-1');

        adoptSubjectFromLink();

        expect(getActiveSubjectId()).toBe('s-1');
    });

    it('is dropped, and nothing else, when the person chooses another', () => {
        expect(withoutSubjectLink('https://app.example.com/wallet/imports/new?import=i-1&subject=s-2#rows')).toBe(
            'https://app.example.com/wallet/imports/new?import=i-1#rows',
        );
    });
});
