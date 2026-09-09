import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { DataTable, Panel } from '../index';

/**
 * `flush` was once declared on `Panel`'s props and never used in its markup.
 *
 * Nothing caught it: TypeScript is satisfied because the prop exists, the pages compiled, the
 * build succeeded, and every screen silently kept its 15px padding while the code read as though
 * the padding had been removed. A prop that is accepted and ignored is worse than one that does
 * not exist — the caller has no way to tell.
 */
describe('Panel', () => {
    it('applies the flush class so a table can reach the card edges', () => {
        const { container } = render(
            <Panel title="Accounts" flush>
                <table />
            </Panel>,
        );

        expect(container.querySelector('.panel')).toHaveClass('panel-flush');
    });

    it('keeps its padding when not flush', () => {
        const { container } = render(<Panel title="Accounts">body</Panel>);

        expect(container.querySelector('.panel')).not.toHaveClass('panel-flush');
    });

    it('renders the foot only when it has something to put in it', () => {
        const { rerender, container } = render(<Panel title="Accounts">body</Panel>);

        expect(container.querySelector('.panel-foot')).toBeNull();

        rerender(
            <Panel title="Accounts" footNote="Applies to every upload">
                body
            </Panel>,
        );

        expect(screen.getByText('Applies to every upload')).toBeInTheDocument();
    });
});

/** Same class of bug as `flush` above: a layout prop that compiles, renders, and does nothing. */
describe('DataTable', () => {
    it('applies the stacked class so rows can restack on a phone', () => {
        const { container } = render(
            <DataTable stacked head={<th>Time</th>}>
                <tr>
                    <td>now</td>
                </tr>
            </DataTable>,
        );

        expect(container.querySelector('table')).toHaveClass('data-table-stack');
    });

    it('scrolls sideways when not stacked', () => {
        const { container } = render(
            <DataTable head={<th>Time</th>}>
                <tr>
                    <td>now</td>
                </tr>
            </DataTable>,
        );

        expect(container.querySelector('table')).not.toHaveClass('data-table-stack');
    });
});
