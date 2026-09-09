import { useEffect } from 'react';
import { subscribeToDocumentsChannel } from '../lib/echo';
import type { DocumentLifecycleEvent } from '../types/documents';

/**
 * Subscribes to `tax-subjects.{subjectId}.documents` for the lifetime of the calling component.
 * `BLUEPRINT.md` §28 rule 15 — "no polling where a Reverb event exists" — this is the only mechanism
 * `documents/inbox.tsx` and `documents/review.tsx` use to learn that a document changed state.
 *
 * `onEvent` should be a stable reference (`useCallback`) — a new function identity resubscribes.
 */
export function useDocumentsChannel(subjectId: string | null, onEvent: (event: DocumentLifecycleEvent) => void): void {
    useEffect(() => {
        if (!subjectId) {
            return;
        }

        const unsubscribe = subscribeToDocumentsChannel(subjectId, { onEvent });

        return unsubscribe;
    }, [subjectId, onEvent]);
}
