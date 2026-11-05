import { apiRequest } from '../lib/http';
import { parseNotificationSettings, parsePushDevices, type NotificationSettings, type PushDevice } from '../types/push';

export async function getNotificationSettings(): Promise<NotificationSettings> {
    return parseNotificationSettings(await apiRequest('/v1/notifications/preferences'));
}

export async function setNotificationType(type: string, enabled: boolean): Promise<void> {
    await apiRequest('/v1/notifications/preferences', { method: 'PATCH', body: { type, enabled } });
}

export async function listPushDevices(): Promise<PushDevice[]> {
    return parsePushDevices(await apiRequest('/v1/notifications/push/devices'));
}

/** Registers this browser's subscription, in the language it is used in. */
export async function registerPushDevice(subscription: PushSubscriptionJSON, locale: string): Promise<void> {
    await apiRequest('/v1/notifications/push/devices', {
        method: 'POST',
        body: { endpoint: subscription.endpoint, keys: subscription.keys, locale },
    });
}

export async function removePushDevice(deviceId: string): Promise<void> {
    await apiRequest(`/v1/notifications/push/devices/${encodeURIComponent(deviceId)}`, { method: 'DELETE' });
}

export async function sendTestPush(): Promise<void> {
    await apiRequest('/v1/notifications/push/test', { method: 'POST' });
}
