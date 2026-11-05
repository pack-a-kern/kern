import { useCallback, useEffect, useState, type JSX } from 'react';
import { Bell, BellOff, Send, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import {
    getNotificationSettings,
    listPushDevices,
    registerPushDevice,
    removePushDevice,
    sendTestPush,
    setNotificationType,
} from '../../api/pushApi';
import DeviceIcon from '../../components/DeviceIcon';
import { EventTime, Panel, PanelButton, PanelNote, Pill, Row, RowList, Status } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { Switch } from '../../components/ui/switch';
import { useTranslation } from '../../i18n';
import { reportApiFailure } from '../../lib/apiErrors';
import { deviceName } from '../../lib/devices';
import { currentSubscription, endpointHash, pushSupport, subscribe, type PushSupport } from '../../lib/push';
import type { NotificationSettings, PushDevice } from '../../types/push';

/** What the card shows, read in one go: the server's settings, the account's devices, and which is this one. */
interface CardState {
    settings: NotificationSettings;
    devices: PushDevice[];
    thisHash: string | null;
}

/**
 * Reads the card's state — and, when this device was registered in another language than the one used
 * now, registers it again, so what arrives on it is written in the language it is read in.
 */
async function readCardState(locale: string): Promise<CardState> {
    const [settings, devices] = await Promise.all([getNotificationSettings(locale), listPushDevices()]);
    const subscription = pushSupport() === 'available' ? await currentSubscription() : null;
    const thisHash = subscription === null ? null : await endpointHash(subscription.endpoint);
    const registered = devices.find((device) => device.endpointHash === thisHash);

    if (subscription !== null && registered !== undefined && registered.locale !== locale) {
        await registerPushDevice(subscription.toJSON(), locale);
    }

    return { settings, devices, thisHash };
}

/**
 * Notifications on the person's own devices: this device on or off, which kinds they receive, and the
 * devices that receive them.
 *
 * The kinds are the server's (`v1/notifications/preferences`), named in the page's language: each
 * package registers its own, so this card has no list of them and a kind a package adds appears here
 * as a switch. The switches decide what reaches a device; the bell keeps every notice regardless.
 */
export default function NotificationsCard(): JSX.Element {
    const { t, locale } = useTranslation();
    const [settings, setSettings] = useState<NotificationSettings | null>(null);
    const [devices, setDevices] = useState<PushDevice[]>([]);
    const [thisHash, setThisHash] = useState<string | null>(null);
    const [support, setSupport] = useState<PushSupport>(() => pushSupport());
    const [busy, setBusy] = useState(false);

    const apply = useCallback((state: CardState): void => {
        setSettings(state.settings);
        setDevices(state.devices);
        setThisHash(state.thisHash);
    }, []);

    useEffect(() => {
        let current = true;

        readCardState(locale).then(
            (state) => {
                if (current) {
                    apply(state);
                }
            },
            (cause: unknown) => {
                if (current) {
                    setSettings({ configured: false, publicKey: null, types: [] });
                    reportApiFailure(
                        cause,
                        t,
                        t((d) => d.pages.account.notifications.loadFailed),
                    );
                }
            },
        );

        return () => {
            current = false;
        };
    }, [apply, locale, t]);

    const thisDeviceOn = thisHash !== null && devices.some((device) => device.endpointHash === thisHash);

    const run = async (work: () => Promise<void>, done: string | null): Promise<void> => {
        setBusy(true);

        try {
            await work();
            apply(await readCardState(locale));

            if (done !== null) {
                toast.success(done);
            }
        } catch (cause: unknown) {
            reportApiFailure(
                cause,
                t,
                t((d) => d.pages.account.notifications.failed),
            );
        } finally {
            setSupport(pushSupport());
            setBusy(false);
        }
    };

    const turnOn = (): Promise<void> =>
        run(
            async () => {
                const subscription = settings?.publicKey == null ? null : await subscribe(settings.publicKey);

                if (subscription !== null) {
                    await registerPushDevice(subscription.toJSON(), locale);
                }
            },
            t((d) => d.pages.account.notifications.turnedOn),
        );

    const turnOff = (): Promise<void> =>
        run(
            async () => {
                const subscription = await currentSubscription();
                const mine = devices.find((device) => device.endpointHash === thisHash);

                if (mine !== undefined) {
                    await removePushDevice(mine.id);
                }

                await subscription?.unsubscribe();
            },
            t((d) => d.pages.account.notifications.turnedOff),
        );

    const words = t((d) => d.pages.account.notifications);

    return (
        <Panel
            title={words.title}
            description={words.description}
            icon={<Bell className="size-4" />}
            footActions={
                thisDeviceOn ? (
                    <PanelButton
                        disabled={busy}
                        onClick={() => {
                            void run(sendTestPush, words.testSent);
                        }}
                    >
                        <Send className="size-3.5" aria-hidden="true" />
                        {words.sendTest}
                    </PanelButton>
                ) : undefined
            }
        >
            {settings === null ? (
                <div className="space-y-2">
                    {[0, 1].map((index) => (
                        <Skeleton key={index} className="tile h-9 w-full" />
                    ))}
                </div>
            ) : !settings.configured ? (
                <PanelNote>{words.notConfigured}</PanelNote>
            ) : (
                <RowList>
                    <Row
                        title={words.thisDevice}
                        subtitle={
                            support === 'ios-not-installed'
                                ? words.iosHint
                                : support === 'unsupported'
                                  ? words.unsupported
                                  : support === 'denied'
                                    ? words.blockedHint
                                    : thisDeviceOn
                                      ? words.onHint
                                      : words.offHint
                        }
                        status={
                            support === 'denied' ? (
                                <Pill tone="locked">{words.blocked}</Pill>
                            ) : thisDeviceOn ? (
                                <Status tone="ok">{words.on}</Status>
                            ) : support === 'available' ? (
                                <Pill tone="neutral">{words.off}</Pill>
                            ) : (
                                <Pill tone="locked">
                                    {support === 'ios-not-installed' ? words.iosTitle : words.off}
                                </Pill>
                            )
                        }
                    >
                        {support !== 'available' ? null : thisDeviceOn ? (
                            <PanelButton
                                disabled={busy}
                                onClick={() => {
                                    void turnOff();
                                }}
                            >
                                <BellOff className="size-3.5" aria-hidden="true" />
                                {words.turnOff}
                            </PanelButton>
                        ) : (
                            <PanelButton
                                primary
                                disabled={busy}
                                onClick={() => {
                                    void turnOn();
                                }}
                            >
                                <Bell className="size-3.5" aria-hidden="true" />
                                {words.turnOn}
                            </PanelButton>
                        )}
                    </Row>

                    {settings.types.map((type) => (
                        <Row key={type.key} title={type.label} subtitle={type.hint}>
                            <Switch
                                checked={type.enabled}
                                disabled={busy}
                                aria-label={type.label}
                                onCheckedChange={(enabled) => {
                                    void run(() => setNotificationType(type.key, enabled), null);
                                }}
                            />
                        </Row>
                    ))}

                    {devices.length === 0 ? null : (
                        <div className="py-2.5 last:pb-0">
                            <div className="text-xs font-semibold">{words.devices}</div>
                            <ul className="divide-border mt-2 divide-y">
                                {devices.map((device) => (
                                    <li key={device.id} className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                                        <DeviceIcon kind={device.deviceKind} />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <span className="text-sm">{deviceName(device, t)}</span>
                                                {device.endpointHash === thisHash ? (
                                                    <Pill tone="accent">
                                                        {t((d) => d.pages.settingsSecurity.thisDevice)}
                                                    </Pill>
                                                ) : null}
                                            </div>
                                            <div className="note mt-0.5">
                                                {words.lastUsed}{' '}
                                                {device.lastUsedAt === null ? (
                                                    words.never
                                                ) : (
                                                    <EventTime iso={device.lastUsedAt} />
                                                )}
                                            </div>
                                        </div>
                                        <PanelButton
                                            variant="destructive"
                                            disabled={busy}
                                            onClick={() => {
                                                void run(async () => {
                                                    await removePushDevice(device.id);

                                                    if (device.endpointHash === thisHash) {
                                                        await (await currentSubscription())?.unsubscribe();
                                                    }
                                                }, words.removed);
                                            }}
                                        >
                                            <Trash2 className="size-3.5" aria-hidden="true" />
                                            {words.remove}
                                        </PanelButton>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </RowList>
            )}
        </Panel>
    );
}
