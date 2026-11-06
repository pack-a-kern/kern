import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SubjectChooser } from '../SubjectSwitcher';

const page = vi.hoisted(() => ({ subjects: [] as Record<string, unknown>[] }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 'u-1' }, subjects: page.subjects } } }),
}));

function subject(id: string, name: string, status = 'ACTIVE'): Record<string, unknown> {
    return { id, display_name: name, status, type: 'PERSON', avatar_updated_at: null };
}

const signOut = <button type="button">Sign out</button>;

describe('the taxpayer chooser', () => {
    beforeEach(() => {
        window.localStorage.clear();
    });

    afterEach(() => {
        // The dialog is portalled to the body, outside what `render` returned: cleared by hand.
        cleanup();
        page.subjects = [];
    });

    it('asks which one, where there are several and none is chosen, and cannot be closed', () => {
        page.subjects = [subject('s-1', 'Ana Pop'), subject('s-2', 'Ion Pop')];

        render(<SubjectChooser footer={signOut} />);

        expect(screen.getByRole('dialog')).toBeTruthy();
        expect(screen.getByText('Ana Pop')).toBeTruthy();
        expect(screen.getByText('Ion Pop')).toBeTruthy();
        // No corner close: the answer, or signing out, is the only way out.
        expect(screen.queryByRole('button', { name: 'Close' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Sign out' })).toBeTruthy();
    });

    it('asks nothing where the choice is already made, or there is only one to make', () => {
        page.subjects = [subject('s-1', 'Ana Pop'), subject('s-2', 'Ion Pop')];
        window.localStorage.setItem('kern.activeSubjectId', 's-2');

        render(<SubjectChooser footer={signOut} />);
        expect(screen.queryByRole('dialog')).toBeNull();
        cleanup();

        window.localStorage.clear();
        // One open taxpayer beside a closed one is one choice, made for the person.
        page.subjects = [subject('s-1', 'Ana Pop'), subject('s-2', 'Ion Pop', 'CLOSED')];

        render(<SubjectChooser footer={signOut} />);
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
