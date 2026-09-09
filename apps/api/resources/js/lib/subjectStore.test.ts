import { afterEach, describe, expect, it } from 'vitest';
import { clearActiveSubjectId, getActiveSubjectId, setActiveSubjectId } from './subjectStore';

describe('subjectStore', () => {
    afterEach(() => {
        window.localStorage.clear();
    });

    it('returns null when nothing is stored', () => {
        expect(getActiveSubjectId()).toBeNull();
    });

    it('round-trips a subject id through localStorage', () => {
        setActiveSubjectId('018f2c1e-aaaa-7bbb-8ccc-000000000000');
        expect(getActiveSubjectId()).toBe('018f2c1e-aaaa-7bbb-8ccc-000000000000');
    });

    it('clears the stored value', () => {
        setActiveSubjectId('018f2c1e-aaaa-7bbb-8ccc-000000000000');
        clearActiveSubjectId();
        expect(getActiveSubjectId()).toBeNull();
    });
});
