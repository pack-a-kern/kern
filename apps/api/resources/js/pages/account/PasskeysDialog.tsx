import { useEffect, useState, type JSX } from 'react';
import { Cloud, KeyRound, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { listPasskeys, removePasskey } from '../../api/authApi';
import { EventTime, PanelButton, PanelDialog, Pill } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { reportApiFailure } from '../../lib/apiErrors';
import { formatDate } from '../../lib/format';
import type { PasskeySummary } from '../../types/auth';

/**
 * The account's passkeys, one per line, each with a way to take it away.
 *
 * Loaded when the dialog opens rather than with the page: the card shows a count, and the list is
 * only worth a request when someone asks to see it. Removing asks once, inline, on the line itself —
 * a second dialog over this one would hide the very passkey being removed. The server then asks for
 * the password again (`verify-recent`), through the app's own step-up dialog.
 */
export default function PasskeysDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onChanged: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const [passkeys, setPasskeys] = useState<PasskeySummary[] | null>(null);
    const [confirming, setConfirming] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const { open } = props;

    useEffect(() => {
        if (!open) {
            return;
        }

        let current = true;

        listPasskeys().then(
            (list) => {
                if (current) {
                    setPasskeys(list);
                }
            },
            (cause: unknown) => {
                if (current) {
                    setPasskeys([]);
                    reportApiFailure(
                        cause,
                        t,
                        t((d) => d.pages.settingsSecurity.passkeysLoadFailed),
                    );
                }
            },
        );

        return () => {
            current = false;
        };
    }, [open, t]);

    const remove = async (passkey: PasskeySummary): Promise<void> => {
        setBusy(true);

        try {
            await removePasskey(passkey.id);
            setPasskeys((list) => (list ?? []).filter((each) => each.id !== passkey.id));
            setConfirming(null);
            toast.success(t((d) => d.pages.settingsSecurity.passkeyRemoved));
            props.onChanged();
        } catch (cause: unknown) {
            reportApiFailure(
                cause,
                t,
                t((d) => d.pages.settingsSecurity.passkeyRemoveFailed),
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <PanelDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setConfirming(null);
                    setPasskeys(null);
                }

                props.onOpenChange(next);
            }}
            title={t((d) => d.pages.settingsSecurity.passkeysDialogTitle)}
            description={t((d) => d.pages.settingsSecurity.passkeysDialogHint)}
            dismissOnOutsideClick
            actions={
                <PanelButton
                    onClick={() => {
                        props.onOpenChange(false);
                    }}
                >
                    {t((d) => d.pages.settingsSecurity.close)}
                </PanelButton>
            }
        >
            {passkeys === null ? (
                <div className="space-y-2">
                    {[0, 1].map((index) => (
                        <Skeleton key={index} className="tile h-14 w-full" />
                    ))}
                </div>
            ) : (
                <ul className="divide-border divide-y rounded-lg border">
                    {passkeys.map((passkey) => (
                        <li key={passkey.id} className="flex flex-col gap-2 p-3">
                            <div className="flex items-start gap-3">
                                <KeyRound className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-semibold">
                                            {passkey.name ?? t((d) => d.pages.settingsSecurity.passkeyUnnamed)}
                                        </span>
                                        {passkey.synced ? (
                                            <Pill tone="neutral">
                                                <Cloud className="size-3" aria-hidden="true" />
                                                {t((d) => d.pages.settingsSecurity.passkeySynced)}
                                            </Pill>
                                        ) : null}
                                    </div>
                                    <div className="note mt-0.5">
                                        {passkey.createdAt === null
                                            ? null
                                            : t((d) => d.pages.settingsSecurity.passkeyAddedOn).replace(
                                                  '{date}',
                                                  formatDate(passkey.createdAt, locale),
                                              )}
                                        {passkey.createdAt === null ? null : ' · '}
                                        {passkey.lastUsedAt === null ? (
                                            t((d) => d.pages.settingsSecurity.passkeyNeverUsed)
                                        ) : (
                                            <>
                                                {t((d) => d.pages.settingsSecurity.passkeyLastUsed)}{' '}
                                                <EventTime iso={passkey.lastUsedAt} />
                                            </>
                                        )}
                                    </div>
                                </div>
                                {confirming === passkey.id ? null : (
                                    <PanelButton
                                        variant="destructive"
                                        disabled={busy}
                                        onClick={() => {
                                            setConfirming(passkey.id);
                                        }}
                                    >
                                        <Trash2 className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.settingsSecurity.removePasskey)}
                                    </PanelButton>
                                )}
                            </div>

                            {confirming === passkey.id ? (
                                <div
                                    role="alert"
                                    className="bg-destructive/5 border-destructive/20 flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2"
                                >
                                    <span className="text-destructive text-xs">
                                        {t((d) => d.pages.settingsSecurity.removePasskeyConfirm)}
                                    </span>
                                    <div className="ml-auto flex gap-2">
                                        <PanelButton
                                            disabled={busy}
                                            onClick={() => {
                                                setConfirming(null);
                                            }}
                                        >
                                            {t((d) => d.pages.settingsSecurity.cancel)}
                                        </PanelButton>
                                        <PanelButton
                                            variant="destructive"
                                            disabled={busy}
                                            onClick={() => {
                                                void remove(passkey);
                                            }}
                                        >
                                            <Trash2 className="size-3.5" aria-hidden="true" />
                                            {t((d) => d.pages.settingsSecurity.removePasskey)}
                                        </PanelButton>
                                    </div>
                                </div>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </PanelDialog>
    );
}
