import { useState, type JSX } from 'react';
import { MonitorSmartphoneIcon } from 'lucide-react';
import { toast } from 'sonner';

import { revokeAllSessions, revokeSession } from '../../api/authApi';
import { DataTable, Panel, PanelButton, Pill } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import type { SessionSummary } from '../../types/auth';

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
                            <td>
                                <div className="identity-name">
                                    {session.userAgent ?? t((d) => d.pages.settingsSecurity.unknownDevice)}
                                </div>
                                {session.isCurrent ? (
                                    <Pill tone="accent">{t((d) => d.pages.settingsSecurity.thisDevice)}</Pill>
                                ) : null}
                            </td>
                            <td className="mono">{session.ipAddress ?? '—'}</td>
                            <td className="whitespace-nowrap">{new Date(session.lastUsedAt).toLocaleString()}</td>
                            <td className="text-right">
                                {session.isCurrent ? null : (
                                    <PanelButton
                                        disabled={busy}
                                        onClick={() => {
                                            void endSession(session);
                                        }}
                                    >
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
