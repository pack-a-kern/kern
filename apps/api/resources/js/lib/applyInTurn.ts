/**
 * One change applied to each item in turn — a bulk action made of single ones.
 *
 * In turn, not all at once, and stopping at the first that fails: what was done before it stays
 * done, and the person is told how far it got and why it stopped, rather than left with an unknown
 * number of rows changed. Each item goes through the endpoint that changes one, so each is checked,
 * refused or recorded in the history exactly as it would be on its own.
 */
export async function applyInTurn<T>(
    items: readonly T[],
    change: (item: T) => Promise<void>,
): Promise<{ done: number; failure: unknown }> {
    let done = 0;

    for (const item of items) {
        try {
            await change(item);
        } catch (failure: unknown) {
            return { done, failure };
        }

        done += 1;
    }

    return { done, failure: undefined };
}
