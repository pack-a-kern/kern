/**
 * What this browser can do with device notifications, and turning them on and off for it.
 *
 * A notification needs three things a browser may not have: a service worker, the Push API, and the
 * permission to show one. iOS and iPadOS give the first two only to a web app on the Home Screen — in a
 * Safari tab they are simply absent — so "unsupported" there is "add it to the Home Screen first", a
 * different thing to tell someone than "this browser cannot".
 */

/** Where this browser stands, in the order the account page explains it. */
export type PushSupport = 'unsupported' | 'ios-not-installed' | 'denied' | 'available';

function isIos(): boolean {
    // iPadOS reports itself as a Mac; a Mac with a touch screen is an iPad.
    return (
        /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

/** Whether this page runs as an installed app rather than in a browser tab. */
function isInstalled(): boolean {
    const standalone = (navigator as Navigator & { standalone?: boolean }).standalone === true;

    return standalone || window.matchMedia('(display-mode: standalone)').matches;
}

export function pushSupport(): PushSupport {
    const capable = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    if (!capable) {
        return isIos() && !isInstalled() ? 'ios-not-installed' : 'unsupported';
    }

    return Notification.permission === 'denied' ? 'denied' : 'available';
}

/** A VAPID key as the browser wants it: the raw bytes of the URL-safe base64 the server sends. */
function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const padded = base64Url
        .replace(/-/g, '+')
        .replace(/_/g, '/')
        .padEnd(Math.ceil(base64Url.length / 4) * 4, '=');
    const binary = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));

    for (let index = 0; index < binary.length; index++) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

/** SHA-256 of a push address, as the server names devices — so this one can be found among them. */
export async function endpointHash(endpoint: string): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));

    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** This browser's current subscription, if it has one. */
export async function currentSubscription(): Promise<PushSubscription | null> {
    const registration = await navigator.serviceWorker.ready;

    return registration.pushManager.getSubscription();
}

/**
 * Asks for the permission — which a browser only shows in answer to a tap — and subscribes.
 *
 * @returns the subscription, or null when the person said no
 */
export async function subscribe(publicKey: string): Promise<PushSubscription | null> {
    if ((await Notification.requestPermission()) !== 'granted') {
        return null;
    }

    const registration = await navigator.serviceWorker.ready;

    return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
}
