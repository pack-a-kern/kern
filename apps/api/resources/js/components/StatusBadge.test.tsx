import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatusBadge from './StatusBadge';

describe('StatusBadge', () => {
    it('renders the Romanian label for READY', () => {
        render(<StatusBadge status="READY" />);
        expect(screen.getByText('Gata pentru verificare')).toBeInTheDocument();
    });

    it('renders the Romanian label for REJECTED', () => {
        render(<StatusBadge status="REJECTED" />);
        expect(screen.getByText('Respins')).toBeInTheDocument();
    });
});
