import { useState, type JSX } from 'react';
import { Bot, Clock, Globe, LogOutIcon, Monitor, MonitorSmartphoneIcon, Smartphone, Tablet } from 'lucide-react';
import { toast } from 'sonner';

import { revokeAllSessions, revokeSession } from '../../api/authApi';
import { CellLabel, DataTable, EventTime, Panel, PanelButton, Pill } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import type { DeviceKind, SessionSummary } from '../../types/auth';

/**
 * The mark for what a session was opened from.
 *
 * Decoration, and deliberately so: the kind is read out of a string the client chose, so nothing is
 * decided from it — see `Kern\Platform\Identity\Enums\DeviceKind`. A robot gets its own mark
 * rather than a laptop's, because a session opened by one is either this installation's own tooling
 * or something worth a second look, and a laptop icon would say neither.
 */
function deviceIcon(kind: DeviceKind): JSX.Element {
    const className = 'text-muted-foreground size-4 shrink-0';

    switch (kind) {
        case 'mobile':
            return <Smartphone className={className} aria-hidden="true" />;
        case 'tablet':
            return <Tablet className={className} aria-hidden="true" />;
        case 'desktop':
            return <Monitor className={className} aria-hidden="true" />;
        case 'robot':
            return <Bot className={className} aria-hidden="true" />;
        default:
            return <MonitorSmartphoneIcon className={className} aria-hidden="true" />;
    }
}

/**
 * What to call the device, in the reader's language.
 *
 * The server sends the browser and the platform as separate fields and never the sentence joining
 * them — "Chrome on Windows" is English, and the word between them is this dictionary's to choose.
 *
 * Falls back through what it has: both parts, then whichever one was read, then the raw agent, then
 * a translated shrug. The raw string is kept as the last resort rather than dropped, because an
 * agent this build has never seen is still something its owner may recognise.
 */
function deviceName(session: SessionSummary, t: <T>(select: (dictionary: Dictionary) => T) => T): string {
    const { deviceBrowser: browser, devicePlatform: platform } = session;

    if (browser !== null && platform !== null) {
        return t((d) => d.pages.settingsSecurity.deviceOn)
            .replace('{browser}', browser)
            .replace('{platform}', platform);
    }

    return browser ?? platform ?? session.userAgent ?? t((d) => d.pages.settingsSecurity.unknownDevice);
}

/**
 * What is signed in as me right now, and how to end it.
 *
 * Revoking is the one destructive action on this page and is always explicit — changing a password
 * deliberately does not revoke anything, so the two questions stay separate.
 */
export default function SessionsCard(props: {
    sessions: SessionSummary[] | null;
    onChanged: () => void;
    describeFailure: (cause: unknown) => string;
}): JSX.Element {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);

    const others = (props.sessions ?? []).filter((session) => !session.isCurrent).length;

    const endSession = async (session: SessionSummary): Promise<void> => {
        setBusy(true);

        try {
            await revokeSession(session.id);
            toast.success(t((d) => d.pages.settingsSecurity.sessionRevoked));
            props.onChanged();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.revokeFailed),
                { description: props.describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    const endOthers = async (): Promise<void> => {
        setBusy(true);

        try {
            const count = await revokeAllSessions(true);
            toast.success(t((d) => d.pages.settingsSecurity.othersRevoked).replace('{count}', String(count)));
            props.onChanged();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSecurity.revokeFailed),
                { description: props.describeFailure(cause) },
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <Panel
            title={t((d) => d.pages.settingsSecurity.sessionsTitle)}
            description={t((d) => d.pages.settingsSecurity.sessionsDescription)}
            icon={<MonitorSmartphoneIcon className="size-4" />}
            flush
            footNote={t((d) => d.pages.settingsSecurity.sessionsNote)}
            footActions={
                others > 0 ? (
                    <PanelButton
                        disabled={busy}
                        onClick={() => {
                            void endOthers();
                        }}
                    >
                        <LogOutIcon className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.settingsSecurity.revokeOthers)}
                    </PanelButton>
                ) : undefined
            }
        >
            {props.sessions === null ? (
                <div className="space-y-2 p-3">
                    {[0, 1].map((index) => (
                        <Skeleton key={index} className="tile h-9 w-full" />
                    ))}
                </div>
            ) : props.sessions.length === 0 ? (
                <p className="note p-4">{t((d) => d.pages.settingsSecurity.noSessions)}</p>
            ) : (
                <DataTable
                    stacked
                    head={
                        <>
                            <th>{t((d) => d.pages.settingsSecurity.columns.device)}</th>
                            <th>{t((d) => d.pages.settingsSecurity.columns.ip)}</th>
                            <th>{t((d) => d.pages.settingsSecurity.columns.lastUsed)}</th>
                            <th />
                        </>
                    }
                >
                    {props.sessions.map((session) => (
                        <tr key={session.id}>
                            <td className="col-primary">
                                <div className="flex items-center gap-2">
                                    {deviceIcon(session.deviceKind)}
                                    <span className="identity-name">{deviceName(session, t)}</span>
                                    {session.isCurrent ? (
                                        <Pill tone="accent">{t((d) => d.pages.settingsSecurity.thisDevice)}</Pill>
                                    ) : null}
                                </div>
                            </td>
                            <td className="mono">
                                <CellLabel icon={<Globe className="size-3.5" aria-hidden="true" />}>
                                    {t((d) => d.pages.settingsSecurity.columns.ip)}
                                </CellLabel>
                                {session.ipAddress ?? '—'}
                            </td>
                            <td className="whitespace-nowrap">
                                <CellLabel icon={<Clock className="size-3.5" aria-hidden="true" />}>
                                    {t((d) => d.pages.settingsSecurity.columns.lastUsed)}
                                </CellLabel>
                                <EventTime iso={session.lastUsedAt} />
                            </td>
                            <td className="col-actions text-right">
                                {session.isCurrent ? null : (
                                    <PanelButton
                                        disabled={busy}
                                        onClick={() => {
                                            void endSession(session);
                                        }}
                                    >
                                        <LogOutIcon className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.settingsSecurity.revoke)}
                                    </PanelButton>
                                )}
                            </td>
                        </tr>
                    ))}
                </DataTable>
            )}
        </Panel>
    );
}
