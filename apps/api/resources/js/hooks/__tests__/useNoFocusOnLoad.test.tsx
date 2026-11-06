import { cleanup, fireEvent, render } from '@testing-library/react';
import type { JSX } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useNoFocusOnLoad } from '../useNoFocusOnLoad';

function Page(): JSX.Element {
    useNoFocusOnLoad();

    return <input aria-label="email" />;
}

function touchScreen(coarse: boolean): void {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: coarse && query === '(pointer: coarse)' }));
}

describe('a page opening with no field focused', () => {
    beforeEach(() => {
        touchScreen(true);
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('lets go of a field the device focused before anyone touched the page', () => {
        const { getByLabelText } = render(<Page />);
        const field = getByLabelText('email');

        field.focus();

        expect(document.activeElement).not.toBe(field);
    });

    it('keeps the field a person reached for', () => {
        const { getByLabelText } = render(<Page />);
        const field = getByLabelText('email');

        fireEvent.pointerDown(field);
        field.focus();

        expect(document.activeElement).toBe(field);
    });

    it('changes nothing on a desktop', () => {
        touchScreen(false);
        const { getByLabelText } = render(<Page />);
        const field = getByLabelText('email');

        field.focus();

        expect(document.activeElement).toBe(field);
    });
});
