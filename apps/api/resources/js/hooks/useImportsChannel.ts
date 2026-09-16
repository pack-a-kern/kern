import { useEffect } from 'react';
import { subscribeToImportsChannel } from '../lib/echo';
import type { StatementImportEvent } from '../types/statementImport';

/**
 * Subscribes to `tax-subjects.{subjectId}.imports` for the lifetime of the calling component.
 *
 * Replaces the timer the import screen used to run. That timer polled only while the status was
 * `READING` or `IMPORTING`, which left `REVIEW` unwatched — and `REVIEW` is exactly when the
 * counterparty proposals arrive, so a person's categories and tags landed in the database and never
 * on their screen.
 *
 * `onEvent` should be a stable reference (`useCallback`) — a new function identity resubscribes.
 */
export function useImportsChannel(subjectId: string | null, onEvent: (event: StatementImportEvent) => void): void {
    useEffect(() => {
        if (!subjectId) {
            return;
        }

        return subscribeToImportsChannel(subjectId, onEvent);
    }, [subjectId, onEvent]);
}
