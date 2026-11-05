import { useState, type JSX } from 'react';
import { LogOutIcon, MonitorSmartphoneIcon } from 'lucide-react';
import { toast } from 'sonner';

import { revokeAllSessions, revokeSession } from '../../api/authApi';
import { reportApiFailure } from '../../lib/apiErrors';
import { EventTime, Panel, PanelButton, Pill } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import type { SessionSummary } from '../../types/auth';
import DeviceIcon from '../../components/DeviceIcon';
import { deviceName } from '../../lib/devices';

/**
 * What is signed in as me right now, and how to end it.
 *
 * Revoking is the one destructive action on this page and is always explicit — changing a password
 * deliberately does not revoke anything, so the two questions stay separate.
 */
export default function SessionsCard(props: { sessions: SessionSummary[] | null; onChanged: () => void }): JSX.Element {
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
            reportApiFailure(
                cause,
                t,
                t((d) => d.pages.settingsSecurity.revokeFailed),
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
            reportApiFailure(
                cause,
                t,
                t((d) => d.pages.settingsSecurity.revokeFailed),
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
                        variant="destructive"
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
                /*
                 * A list rather than the stacked table: a session is three facts, and on a phone the
                 * table's label-per-value blocks turned each one into five lines — one of them empty —
                 * with the button that ends it adrift between them. Here a device is one line to read
                 * and one control on it, the same at every width. Striped as the tables are, at the same
                 * 45% of `--muted` (`.data-table tbody tr:nth-child(even) td`).
                 */
                <ul className="divide-border divide-y">
                    {props.sessions.map((session) => (
                        <li key={session.id} className="even:bg-muted/45 flex items-center gap-3 px-4 py-3">
                            <DeviceIcon kind={session.deviceKind} />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span className="identity-name text-sm">{deviceName(session, t)}</span>
                                    {session.isCurrent ? (
                                        <Pill tone="accent">{t((d) => d.pages.settingsSecurity.thisDevice)}</Pill>
                                    ) : null}
                                </div>
                                <div className="note mt-0.5 flex flex-wrap items-center gap-x-1.5">
                                    <span className="mono" title={t((d) => d.pages.settingsSecurity.columns.ip)}>
                                        {session.ipAddress ?? '—'}
                                    </span>
                                    <span aria-hidden="true">·</span>
                                    <span className="whitespace-nowrap">
                                        <EventTime iso={session.lastUsedAt} />
                                    </span>
                                </div>
                            </div>
                            {session.isCurrent ? null : (
                                <PanelButton
                                    variant="destructive"
                                    disabled={busy}
                                    onClick={() => {
                                        void endSession(session);
                                    }}
                                >
                                    <LogOutIcon className="size-3.5" aria-hidden="true" />
                                    {t((d) => d.pages.settingsSecurity.revoke)}
                                </PanelButton>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
