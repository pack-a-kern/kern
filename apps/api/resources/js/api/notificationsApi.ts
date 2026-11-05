import { apiRequest } from '../lib/http';
import { parseNotificationFeed, type NotificationFeed } from '../types/notifications';

/** The signed-in person's own notices, whichever taxpayer each is about, written in `locale`. */
export async function listNotifications(locale: string): Promise<NotificationFeed> {
    const query = new URLSearchParams({ locale });

    return parseNotificationFeed(JSON.parse(await apiRequest(`/v1/notifications?${query.toString()}`)) as unknown);
}

export async function markAllNotificationsRead(): Promise<void> {
    await apiRequest('/v1/notifications/read', { method: 'POST' });
}
