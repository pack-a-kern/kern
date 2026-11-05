import type { Dictionary } from '../i18n/dictionary';

/**
 * What to call a device, in the reader's language — a signed-in session or one that receives
 * notifications, which the server describes the same way.
 *
 * The server sends the browser and the platform as separate fields and never the sentence joining
 * them — "Chrome on Windows" is English, and the word between them is this dictionary's to choose.
 *
 * Falls back through what it has: both parts, then whichever one was read, then the raw agent, then a
 * translated shrug. The raw string is kept as the last resort rather than dropped, because an agent
 * this build has never seen is still something its owner may recognise.
 */
export function deviceName(
    device: { deviceBrowser: string | null; devicePlatform: string | null; userAgent?: string | null },
    t: <T>(select: (dictionary: Dictionary) => T) => T,
): string {
    const { deviceBrowser: browser, devicePlatform: platform } = device;

    if (browser !== null && platform !== null) {
        return t((d) => d.pages.settingsSecurity.deviceOn)
            .replace('{browser}', browser)
            .replace('{platform}', platform);
    }

    return browser ?? platform ?? device.userAgent ?? t((d) => d.pages.settingsSecurity.unknownDevice);
}
