import { parseJson, vArray, vBoolean, vNullable, vObject, vString } from '../lib/validate';

/**
 * The account's device notifications, as `v1/notifications/preferences` and `…/push/devices` give them.
 */

/** One kind of notice the server sends, named in the language the page asked for. */
export interface NotificationTypeSetting {
    key: string;
    label: string;
    hint: string;
    enabled: boolean;
}

export interface NotificationSettings {
    /** Whether this server has the keys to send device notifications at all. */
    configured: boolean;
    /** What a browser subscribes with (VAPID), or null when the server has none. */
    publicKey: string | null;
    types: NotificationTypeSetting[];
}

/** One device receiving this account's notifications — never its address or keys. */
export interface PushDevice {
    id: string;
    /** SHA-256 of the device's push address, which is how the page tells "this device" among them. */
    endpointHash: string;
    /** The language its notifications are written in — the one it turned them on in. */
    locale: string;
    /** What it is — phone, tablet, desktop — or `unknown` when the server could not tell. */
    deviceKind: string;
    deviceBrowser: string | null;
    devicePlatform: string | null;
    createdAt: string | null;
    lastUsedAt: string | null;
}

export function parseNotificationSettings(text: string): NotificationSettings {
    const parsed = parseJson(
        text,
        vObject({
            configured: vBoolean,
            public_key: vNullable(vString),
            types: vArray(vObject({ key: vString, label: vString, hint: vString, enabled: vBoolean })),
        }),
    );

    return { configured: parsed.configured, publicKey: parsed.public_key, types: parsed.types };
}

export function parsePushDevices(text: string): PushDevice[] {
    const parsed = parseJson(
        text,
        vObject({
            devices: vArray(
                vObject({
                    id: vString,
                    endpoint_hash: vString,
                    locale: vString,
                    device_kind: vNullable(vString),
                    device_browser: vNullable(vString),
                    device_platform: vNullable(vString),
                    created_at: vNullable(vString),
                    last_used_at: vNullable(vString),
                }),
            ),
        }),
    );

    return parsed.devices.map((device) => ({
        id: device.id,
        endpointHash: device.endpoint_hash,
        locale: device.locale,
        deviceKind: device.device_kind ?? 'unknown',
        deviceBrowser: device.device_browser,
        devicePlatform: device.device_platform,
        createdAt: device.created_at,
        lastUsedAt: device.last_used_at,
    }));
}
