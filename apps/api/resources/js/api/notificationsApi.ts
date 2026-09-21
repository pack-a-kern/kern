import { apiRequest } from '../lib/http';
import { parseNotificationFeed, type NotificationFeed } from '../types/notifications';

/** The signed-in person's own notices, whichever taxpayer each is about. */
export async function listNotifications(): Promise<NotificationFeed> {
    return parseNotificationFeed(JSON.parse(await apiRequest('/v1/notifications')) as unknown);
}

export async function markAllNotificationsRead(): Promise<void> {
    await apiRequest('/v1/notifications/read', { method: 'POST' });
}
