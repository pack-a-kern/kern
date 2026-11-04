import { useSharedAuth, type SharedAuth } from '../lib/sharedProps';
import { getActiveSubjectId } from '../lib/subjectStore';

type SharedSubject = SharedAuth['subjects'][number];

/**
 * The taxpayers this person can work in: the ones that came with the page, less any that are closed.
 * A closed taxpayer is kept for its records, not for filing against.
 */
export function useOpenSubjects(): SharedSubject[] {
    return (useSharedAuth()?.subjects ?? []).filter((subject) => subject.status !== 'CLOSED');
}

/**
 * Which of `subjectIds` this browser is working in.
 *
 * The stored choice when it is still one of them; the only one when there is exactly one, because a
 * question with one possible answer is not a question; otherwise none, and the person is asked.
 */
export function resolveActiveSubjectId(subjectIds: readonly string[], stored: string | null): string | null {
    if (stored !== null && subjectIds.includes(stored)) {
        return stored;
    }

    return subjectIds.length === 1 ? (subjectIds[0] ?? null) : null;
}

/**
 * The taxpayer every screen works in — worked out on the spot, never read back from storage alone.
 *
 * Pages used to read the stored id on their first render, while the sidebar's switcher chose the only
 * taxpayer in an effect that ran *after* it. On a new device the page found nothing, asked for a
 * taxpayer, and kept asking until a reload found the id the switcher had saved in the meantime. Every
 * reader now derives the same answer from the same two inputs — the list sent with the page and the
 * stored choice — so there is no order for them to race in. The switcher still saves what it shows
 * (`SubjectSwitcher`), so the choice survives the next visit.
 */
export function useActiveSubjectId(): string | null {
    const subjects = useOpenSubjects();

    return resolveActiveSubjectId(
        subjects.map((subject) => subject.id),
        getActiveSubjectId(),
    );
}
