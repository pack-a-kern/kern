import { describe, expect, it } from 'vitest';
import { noticeIdFrom, parseNotificationFeed } from './notifications';

const written = {
    id: 'n-1',
    type: 'order-shipped',
    title: 'Order #1042 has shipped',
    body: 'With Northwind Freight',
    url: '/orders/1042',
    level: 'info',
    created_at: '2026-11-05T09:00:00+00:00',
};

describe('parseNotificationFeed', () => {
    it('reads notices already in words, whatever their kind', () => {
        const feed = parseNotificationFeed({ notifications: [written], unread: 3 });

        expect(feed).toEqual({
            notices: [
                {
                    id: 'n-1',
                    type: 'order-shipped',
                    title: 'Order #1042 has shipped',
                    body: 'With Northwind Freight',
                    url: '/orders/1042',
                    level: 'info',
                    createdAt: '2026-11-05T09:00:00+00:00',
                },
            ],
            unread: 3,
        });
    });

    it('leaves out a notice that is not in that shape, and keeps the rest', () => {
        const feed = parseNotificationFeed({
            notifications: [
                { ...written, title: undefined },
                { ...written, id: 'n-2', level: 'loud' },
                { ...written, id: 'n-3' },
            ],
            unread: 1,
        });

        expect(feed.notices.map((entry) => entry.id)).toEqual(['n-3']);
    });

    it('reads nothing from a body that is not a feed', () => {
        expect(parseNotificationFeed('nope')).toEqual({ notices: [], unread: 0 });
    });
});

describe('noticeIdFrom', () => {
    it('takes the id the socket announces, and nothing else', () => {
        expect(noticeIdFrom({ id: 'n-9', type: 'deposit-maturity' })).toBe('n-9');
        expect(noticeIdFrom({ type: 'deposit-maturity' })).toBeNull();
    });
});
