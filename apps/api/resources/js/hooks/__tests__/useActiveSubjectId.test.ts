import { describe, expect, it } from 'vitest';
import { resolveActiveSubjectId } from '../useActiveSubjectId';

describe('resolveActiveSubjectId', () => {
    it('chooses the only taxpayer on a device that has stored nothing — the first login there', () => {
        expect(resolveActiveSubjectId(['only'], null)).toBe('only');
    });

    it('keeps the stored choice while it is still on offer', () => {
        expect(resolveActiveSubjectId(['a', 'b'], 'b')).toBe('b');
    });

    it('drops a stored choice that is no longer on offer — closed, or access taken away', () => {
        expect(resolveActiveSubjectId(['a', 'b'], 'gone')).toBeNull();
        expect(resolveActiveSubjectId(['a'], 'gone')).toBe('a');
    });

    it('asks when there are several and none was chosen', () => {
        expect(resolveActiveSubjectId(['a', 'b'], null)).toBeNull();
    });

    it('has nothing to choose without taxpayers', () => {
        expect(resolveActiveSubjectId([], 'a')).toBeNull();
    });
});
