import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import StatusBadge from './StatusBadge';

describe('StatusBadge', () => {
    it('renders the label for READY in the default language, English', () => {
        render(<StatusBadge status="READY" />);
        expect(screen.getByText('Ready for review')).toBeInTheDocument();
    });

    it('renders the label for REJECTED in the default language, English', () => {
        render(<StatusBadge status="REJECTED" />);
        expect(screen.getByText('Rejected')).toBeInTheDocument();
    });
});
