import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { setActiveSubjectId } from '../../lib/subjectStore';
import { useRememberedPaging, useRememberedState } from '../useRememberedState';

describe('useRememberedState', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('comes back with the value a screen was left on', () => {
        const first = renderHook(() => useRememberedState<string>('logs.tab', 'audit'));

        act(() => {
            first.result.current[1]('tasks');
        });
        first.unmount();

        // A refresh, or a visit to another screen and back.
        const again = renderHook(() => useRememberedState<string>('logs.tab', 'audit'));

        expect(again.result.current[0]).toBe('tasks');
    });

    it('keeps a separate value for each taxpayer when asked to', () => {
        setActiveSubjectId('subject-a');
        const a = renderHook(() => useRememberedState<string>('wallet.transactions.account', '', { perSubject: true }));

        act(() => {
            a.result.current[1]('account-of-a');
        });
        a.unmount();

        setActiveSubjectId('subject-b');
        const b = renderHook(() => useRememberedState<string>('wallet.transactions.account', '', { perSubject: true }));

        expect(b.result.current[0]).toBe('');
    });

    it('ignores a stored value of the wrong type or one the screen no longer offers', () => {
        window.localStorage.setItem('kern.view.logs.jobs.status', JSON.stringify('archived'));
        window.localStorage.setItem('kern.view.logs.audit.page', JSON.stringify('four'));

        const status = renderHook(() =>
            useRememberedState<string>('logs.jobs.status', 'all', {
                isValid: (value) => ['all', 'failed'].includes(value),
            }),
        );
        const page = renderHook(() => useRememberedState<number>('logs.audit.page', 1));

        expect(status.result.current[0]).toBe('all');
        expect(page.result.current[0]).toBe(1);
    });

    it('leaves nothing behind once a value is back at its default', () => {
        const { result } = renderHook(() => useRememberedState<string>('logs.tab', 'audit'));

        act(() => {
            result.current[1]('jobs');
        });
        act(() => {
            result.current[1]('audit');
        });

        expect(window.localStorage.getItem('kern.view.logs.tab')).toBeNull();
    });
});

describe('useRememberedPaging', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    it('starts again from the first page when the page size changes', () => {
        const { result } = renderHook(() => useRememberedPaging('logs.audit'));

        act(() => {
            result.current.setPage(4);
        });
        act(() => {
            result.current.setPageSize(100);
        });

        expect(result.current.page).toBe(1);
        expect(result.current.pageSize).toBe(100);
    });

    it('refuses a remembered page size the pager does not offer', () => {
        window.localStorage.setItem('kern.view.logs.audit.pageSize', JSON.stringify(7));

        const { result } = renderHook(() => useRememberedPaging('logs.audit'));

        expect(result.current.pageSize).toBe(25);
    });
});

describe('useRememberedPaging', () => {
    it('moves to the page it is asked for, and stays there', () => {
        const paging = renderHook(() => useRememberedPaging('wallet.transactions', { perSubject: true }));

        expect(paging.result.current.page).toBe(1);

        act(() => {
            paging.result.current.setPage(2);
        });

        expect(paging.result.current.page).toBe(2);

        // A re-render must not read the page back from storage as the default: the whole symptom of
        // the bug this guards is a pager that moves and a list that does not.
        paging.rerender();

        expect(paging.result.current.page).toBe(2);

        act(() => {
            paging.result.current.setPage(3);
        });

        expect(paging.result.current.page).toBe(3);
    });

    it('starts again from the first page when the page size changes', () => {
        const paging = renderHook(() => useRememberedPaging('wallet.reports'));

        act(() => {
            paging.result.current.setPage(4);
        });

        act(() => {
            paging.result.current.setPageSize(100);
        });

        expect(paging.result.current.page).toBe(1);
        expect(paging.result.current.pageSize).toBe(100);
    });
});
