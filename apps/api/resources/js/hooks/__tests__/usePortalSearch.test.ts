import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { PortalSearchState } from '../../types/legislation';

const api = vi.hoisted(() => ({
    startPortalSearch: vi.fn(),
    getPortalSearch: vi.fn(),
    cancelPortalSearch: vi.fn(),
}));
const channel = vi.hoisted(() => ({ listener: null as ((searchId: string) => void) | null, unsubscribe: vi.fn() }));

vi.mock('../../api/legislationApi', () => api);
vi.mock('../../lib/echo', () => ({
    subscribeToPortalSearches: (_userId: string, onChange: (searchId: string) => void) => {
        channel.listener = onChange;

        return channel.unsubscribe;
    },
}));
vi.mock('../../lib/sharedProps', () => ({ useSharedAuth: () => ({ user: { id: 'u-1' } }) }));

const { usePortalSearch } = await import('../usePortalSearch');

function search(status: PortalSearchState['status'], pagesRead = 0): PortalSearchState {
    return { id: 's-1', status, pagesRead, failure: null, pollAfterMs: status === 'running' ? 5000 : null, acts: [] };
}

describe('usePortalSearch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        channel.listener = null;
        channel.unsubscribe.mockReset();
        api.startPortalSearch.mockResolvedValue(search('running'));
        api.getPortalSearch.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('asks again when the socket says the search moved, and on the interval the server gave when it does not', async () => {
        api.getPortalSearch.mockResolvedValue(search('running', 1));
        const { result } = renderHook(() => usePortalSearch());

        await act(async () => {
            await result.current.start({ title: 'Programului Tezaur', year: null }, null);
        });

        await act(async () => {
            channel.listener?.('s-1');
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(api.getPortalSearch).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
        expect(api.getPortalSearch).toHaveBeenCalledTimes(2);
        expect(result.current.search?.pagesRead).toBe(1);
    });

    it('ignores messages about another search', async () => {
        const { result } = renderHook(() => usePortalSearch());

        await act(async () => {
            await result.current.start({ title: 'Programului Tezaur', year: null }, null);
        });
        act(() => {
            channel.listener?.('someone-elses');
        });

        expect(api.getPortalSearch).not.toHaveBeenCalled();
    });

    it('never has two requests in flight: a message during one is answered by one more after it', async () => {
        let answer: (value: PortalSearchState) => void = () => undefined;
        api.getPortalSearch.mockImplementationOnce(
            () =>
                new Promise<PortalSearchState>((resolve) => {
                    answer = resolve;
                }),
        );
        api.getPortalSearch.mockResolvedValue(search('running', 2));
        const { result } = renderHook(() => usePortalSearch());

        await act(async () => {
            await result.current.start({ title: 'Programului Tezaur', year: null }, null);
        });
        act(() => {
            channel.listener?.('s-1');
            channel.listener?.('s-1');
            channel.listener?.('s-1');
        });
        expect(api.getPortalSearch).toHaveBeenCalledTimes(1);

        await act(async () => {
            answer(search('running', 1));
            await Promise.resolve();
        });
        expect(api.getPortalSearch).toHaveBeenCalledTimes(2);
    });

    it('stops listening and asking once the search is finished', async () => {
        api.getPortalSearch.mockResolvedValue(search('done', 2));
        const { result } = renderHook(() => usePortalSearch());

        await act(async () => {
            await result.current.start({ title: 'Programului Tezaur', year: null }, null);
        });
        await act(async () => {
            channel.listener?.('s-1');
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(result.current.search?.status).toBe('done');
        expect(channel.unsubscribe).toHaveBeenCalledTimes(1);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(60_000);
        });
        expect(api.getPortalSearch).toHaveBeenCalledTimes(1);
    });

    it('lets go of everything when the screen goes: the listener, the timer and the request in flight', async () => {
        let signal: AbortSignal | undefined;
        api.getPortalSearch.mockImplementation((_id: string, given?: AbortSignal) => {
            signal = given;

            return new Promise(() => undefined);
        });
        const { result, unmount } = renderHook(() => usePortalSearch());

        await act(async () => {
            await result.current.start({ title: 'Programului Tezaur', year: null }, null);
        });
        act(() => {
            channel.listener?.('s-1');
        });
        unmount();

        expect(signal?.aborted).toBe(true);
        expect(channel.unsubscribe).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(60_000);
        expect(api.getPortalSearch).toHaveBeenCalledTimes(1);
    });

    it('cancels only a search that is running', async () => {
        api.cancelPortalSearch.mockResolvedValue(search('cancelled'));
        const { result } = renderHook(() => usePortalSearch());

        await act(async () => {
            await result.current.cancel();
        });
        expect(api.cancelPortalSearch).not.toHaveBeenCalled();

        await act(async () => {
            await result.current.start({ title: 'Programului Tezaur', year: null }, null);
        });
        await act(async () => {
            await result.current.cancel();
        });

        expect(api.cancelPortalSearch).toHaveBeenCalledWith('s-1');
        expect(result.current.search?.status).toBe('cancelled');
    });
});
