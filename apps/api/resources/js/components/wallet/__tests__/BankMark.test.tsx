import { fireEvent, render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { Bank } from '../../../types/wallet';
import BankMark from '../BankMark';
import { bankOptions } from '../bankOptions';

const bt: Bank = {
    id: 'b-1',
    name: 'Banca Transilvania',
    code: 'BTRL',
    countryCode: 'RO',
    active: true,
    group: null,
    logoUrl: '/api/v1/banks/b-1/logo?v=abc',
};

describe('a bank’s mark', () => {
    it('shows the logo through an image, which runs nothing an SVG holds', () => {
        const { container } = render(<BankMark bank={bt} />);

        expect(container.querySelector('img')?.getAttribute('src')).toBe('/api/v1/banks/b-1/logo?v=abc');
    });

    it('shows the initials where there is no logo, and where it fails to load', () => {
        const { container, rerender } = render(<BankMark bank={{ ...bt, logoUrl: null }} />);
        expect(container.textContent).toBe('BT');

        rerender(<BankMark bank={bt} />);
        const image = container.querySelector('img');
        expect(image).not.toBeNull();

        if (image !== null) {
            fireEvent.error(image);
        }

        expect(container.querySelector('img')).toBeNull();
        expect(container.textContent).toBe('BT');
    });

    it('comes in the size its place needs', () => {
        const { container, rerender } = render(<BankMark bank={bt} size="sm" />);
        expect(container.firstElementChild?.classList.contains('bank-tile-sm')).toBe(true);

        rerender(<BankMark bank={bt} size="lg" />);
        expect(container.firstElementChild?.classList.contains('bank-tile-lg')).toBe(true);

        rerender(<BankMark bank={bt} />);
        expect(
            [...(container.firstElementChild?.classList ?? [])].some((name) => /^bank-tile-(sm|lg)$/.test(name)),
        ).toBe(false);
    });

    it('greys a closed bank', () => {
        const { container } = render(<BankMark bank={{ ...bt, active: false }} />);

        expect(container.firstElementChild?.classList.contains('bank-tile-closed')).toBe(true);
    });
});

describe('a bank as the selects offer it', () => {
    it('says its group and whether it closed, beneath the name', () => {
        const [grouped, closed, both, plain] = bankOptions(
            [
                { ...bt, group: 'Erste Group Bank' },
                { ...bt, id: 'b-2', active: false },
                { ...bt, id: 'b-3', active: false, group: 'Erste Group Bank' },
                { ...bt, id: 'b-4' },
            ],
            'closed',
        );

        expect([grouped?.description, closed?.description, both?.description, plain?.description]).toEqual([
            'Erste Group Bank',
            'closed',
            'Erste Group Bank · closed',
            undefined,
        ]);
    });
});
