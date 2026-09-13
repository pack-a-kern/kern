import { useCallback, useEffect, useState, type JSX } from 'react';
import { EllipsisVertical, Info, PauseCircle, Pencil, PlayCircle, Plus, UsersIcon, XCircle } from 'lucide-react';
import { toast } from 'sonner';

import { listDocumentAiConsents, listSubjects, setDocumentAiConsent, setSubjectStatus } from '../../api/accountApi';
import { setDisplayCurrency } from '../../api/walletApi';
import AppShell from '../../components/AppShell';
import { DataTable, Identity, Page, Panel, PanelButton, PanelSelect, Pill, Status } from '../../components/panel';
import Pagination, { PAGE_SIZES } from '../../components/Pagination';
import { Switch } from '../../components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import type { SubjectsPage, TaxSubject } from '../../types/account';
import { CURRENCIES } from '../../types/wallet';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '../../components/ui/dropdown-menu';
import ConfirmDialog from '../../components/ConfirmDialog';
import AddSubjectDialog from './AddSubjectDialog';
import EditSubjectDialog from './EditSubjectDialog';

/** Initials for the avatar disc, from a display name rather than an email. */
function initialsFor(name: string): string {
    const parts = name.split(/\s+/).filter((part) => part !== '');

    return (parts.length > 1 ? `${parts[0]?.[0] ?? ''}${parts[1]?.[0] ?? ''}` : name.slice(0, 2)).toUpperCase();
}

/**
 * The taxpayers this account can act for.
 *
 * A taxpayer — not the login — is what owns documents, declarations and encrypted personal data:
 * each has its own data-encryption key, its own audit chain, and every business query in the
 * application is scoped to one. That is why names and codes appear here and not on the account
 * screen, and why a membership carries a role of its own.
 *
 * ## Why AI consent and display currency are columns
 *
 * Both are settings *of a taxpayer*, and both were cards elsewhere: consent on a privacy screen,
 * currency on a card that applied to whichever taxpayer happened to be active. As cards they were
 * either a second list of the same rows or a control whose scope nobody could see. As columns they
 * sit on the row they belong to, and a table of three taxpayers shows three answers instead of one.
 */
export default function SubjectsPage(): JSX.Element {
    const { t } = useTranslation();

    const [page, setPage] = useState<SubjectsPage | null>(null);
    const [pageNumber, setPageNumber] = useState(1);
    const [pageSize, setPageSize] = useState<number>(PAGE_SIZES[1]);
    const [consent, setConsent] = useState<Record<string, boolean>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloadToken, setReloadToken] = useState(0);
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<TaxSubject | null>(null);
    const [closing, setClosing] = useState<TaxSubject | null>(null);

    const describeFailure = useCallback((cause: unknown): string => describeApiFailure(cause, t), [t]);

    useEffect(() => {
        let active = true;

        // Both together: the switches must show the state that is actually stored. Rendering them
        // all "off" until touched would tell someone their consent had been revoked when it had
        // not — the exact misreading a consent control must not produce.
        Promise.all([listSubjects(pageNumber, pageSize), listDocumentAiConsents()])
            .then(([loaded, consented]) => {
                if (active) {
                    setPage(loaded);
                    setConsent(
                        Object.fromEntries(loaded.data.map((subject) => [subject.id, consented.has(subject.id)])),
                    );
                    setFailed(false);
                }
            })
            .catch((cause: unknown) => {
                if (active) {
                    setPage(null);
                    setFailed(true);
                    toast.error(
                        t((d) => d.pages.settingsSubjects.loadFailed),
                        { description: describeFailure(cause) },
                    );
                }
            });

        return () => {
            active = false;
        };
    }, [reloadToken, pageNumber, pageSize, describeFailure, t]);

    const toggleConsent = async (subject: TaxSubject, next: boolean): Promise<void> => {
        setBusy(subject.id);

        try {
            await setDocumentAiConsent(subject.id, next);
            setConsent((current) => ({ ...current, [subject.id]: next }));
            toast.success(
                next
                    ? t((d) => d.pages.settingsPrivacy.consentGranted)
                    : t((d) => d.pages.settingsPrivacy.consentRevoked),
            );
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsPrivacy.consentFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(null);
        }
    };

    const changeCurrency = async (subject: TaxSubject, next: string): Promise<void> => {
        setBusy(subject.id);

        try {
            await setDisplayCurrency(subject.id, next);
            // The row is the source of truth on screen, so it is updated here rather than by
            // refetching the list — and only after the server has accepted the change.
            setPage((current) =>
                current === null
                    ? null
                    : {
                          ...current,
                          data: current.data.map((row) =>
                              row.id === subject.id ? { ...row, displayCurrency: next } : row,
                          ),
                      },
            );
            toast.success(t((d) => d.pages.settingsSubjects.displayCurrencySaved));
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSubjects.displayCurrencyFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(null);
        }
    };

    const changeStatus = async (subject: TaxSubject, status: 'ACTIVE' | 'SUSPENDED' | 'CLOSED'): Promise<void> => {
        setBusy(subject.id);

        try {
            await setSubjectStatus(subject.id, status);
            toast.success(t((d) => d.pages.settingsSubjects.statusChanged));

            // A full reload, not a local patch of the row: the taxpayer's status decides what the
            // sidebar switcher offers and what the rest of the interface lets you do, and both are
            // drawn from the page's own props. Rewriting one cell would leave a reactivated
            // taxpayer missing from the switcher until the next navigation.
            window.location.reload();

            return;
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.settingsSubjects.statusFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setBusy(null);
            setClosing(null);
        }
    };

    const rows = page?.data ?? null;

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.settingsSubjects.title)}
                lede={t((d) => d.pages.settingsSubjects.description)}
                actions={
                    failed ? (
                        <PanelButton
                            onClick={() => {
                                setPage(null);
                                setFailed(false);
                                setReloadToken((token) => token + 1);
                            }}
                        >
                            {t((d) => d.pages.settingsSubjects.retry)}
                        </PanelButton>
                    ) : undefined
                }
            >
                <Panel
                    title={t((d) => d.pages.settingsSubjects.tableTitle)}
                    description={t((d) => d.pages.settingsSubjects.tableDescription)}
                    icon={<UsersIcon className="size-4" />}
                    flush
                    // In the card's head, where the documents list keeps its own: the button acts
                    // on what is below it, and a page-level action implies it acts on the page.
                    aside={
                        <PanelButton
                            primary
                            inline
                            onClick={() => {
                                setAdding(true);
                            }}
                        >
                            <Plus className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.settingsSubjects.add)}
                        </PanelButton>
                    }
                    foot={
                        page === null ? undefined : (
                            <Pagination
                                page={page.page}
                                pageSize={page.perPage}
                                total={page.total}
                                unit={t((d) => d.pages.settingsSubjects.unit)}
                                onPageChange={setPageNumber}
                                onPageSizeChange={(size) => {
                                    setPageSize(size);
                                    setPageNumber(1);
                                }}
                            />
                        )
                    }
                >
                    {rows === null ? (
                        <div className="space-y-2 p-3">
                            {[0, 1].map((index) => (
                                <Skeleton key={index} className="tile h-11 w-full" />
                            ))}
                        </div>
                    ) : rows.length === 0 ? (
                        <p className="note p-4">{t((d) => d.pages.settingsSubjects.empty)}</p>
                    ) : (
                        <DataTable
                            // On a phone the row stacks: the name takes the first line, and the
                            // pills and controls flow onto the next rather than scrolling sideways
                            // out of reach. Which columns survive is a judgement about the content,
                            // so each cell says so below.
                            stacked
                            head={
                                <>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.subject)}</th>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.type)}</th>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.role)}</th>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.status)}</th>
                                    <th>{t((d) => d.pages.settingsSubjects.columns.currency)}</th>
                                    <th>
                                        <span className="inline-flex items-center gap-1">
                                            {t((d) => d.pages.settingsSubjects.columns.ai)}
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span
                                                        className="text-muted-foreground inline-flex"
                                                        aria-label={t((d) => d.pages.settingsPrivacy.consentNotice)}
                                                    >
                                                        <Info className="size-3" />
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent side="top" className="max-w-72">
                                                    {t((d) => d.pages.settingsPrivacy.consentNotice)}
                                                </TooltipContent>
                                            </Tooltip>
                                        </span>
                                    </th>
                                    <th />
                                </>
                            }
                        >
                            {rows.map((subject) => (
                                <tr key={subject.id}>
                                    <td className="col-primary">
                                        <Identity
                                            initials={initialsFor(subject.displayName)}
                                            name={subject.displayName}
                                            sub={subject.id}
                                        />
                                    </td>
                                    <td className="col-hide">
                                        <Pill>
                                            {t((d) => d.pages.settingsSubjects.types[subject.type] ?? subject.type)}
                                        </Pill>
                                    </td>
                                    <td className="col-hide">
                                        <Pill tone={subject.role === 'OWNER' ? 'accent' : 'neutral'}>
                                            {t((d) => d.pages.settingsSubjects.roles[subject.role] ?? subject.role)}
                                        </Pill>
                                    </td>
                                    <td>
                                        <Status tone={subject.status === 'ACTIVE' ? 'ok' : 'warn'}>
                                            {t(
                                                (d) =>
                                                    d.pages.settingsSubjects.statuses[subject.status] ?? subject.status,
                                            )}
                                        </Status>
                                    </td>
                                    <td data-label={t((d) => d.pages.settingsSubjects.columns.currency)}>
                                        {/* Saved on change: one value, nothing to validate it
                                            against, and a Save button beside a single select is
                                            ceremony. Only an owner may change it. */}
                                        <PanelSelect
                                            className="w-24 sm:w-28"
                                            aria-label={t((d) => d.pages.settingsSubjects.columns.currency)}
                                            value={subject.displayCurrency}
                                            disabled={
                                                busy === subject.id ||
                                                subject.role !== 'OWNER' ||
                                                subject.status !== 'ACTIVE'
                                            }
                                            onValueChange={(next) => {
                                                if (next !== '' && next !== subject.displayCurrency) {
                                                    void changeCurrency(subject, next);
                                                }
                                            }}
                                            options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                                        />
                                    </td>
                                    <td data-label={t((d) => d.pages.settingsSubjects.columns.ai)}>
                                        <Switch
                                            aria-label={`${t((d) => d.pages.settingsSubjects.columns.ai)} — ${subject.displayName}`}
                                            checked={consent[subject.id] === true}
                                            disabled={
                                                busy === subject.id ||
                                                subject.role !== 'OWNER' ||
                                                subject.status !== 'ACTIVE'
                                            }
                                            onCheckedChange={(checked) => {
                                                void toggleConsent(subject, checked);
                                            }}
                                        />
                                    </td>
                                    <td className="col-end">
                                        <div className="flex justify-end">
                                            {/* Only an owner may change what a taxpayer is or whether
                                            it is in use; for anyone else the row is read-only and
                                            the menu would be three disabled items. */}
                                            {subject.role !== 'OWNER' ? null : subject.status !== 'ACTIVE' ? (
                                                <PanelButton
                                                    disabled={busy === subject.id}
                                                    onClick={() => {
                                                        void changeStatus(subject, 'ACTIVE');
                                                    }}
                                                >
                                                    <PlayCircle className="size-3.5" aria-hidden="true" />
                                                    {t((d) => d.pages.settingsSubjects.reactivate)}
                                                </PanelButton>
                                            ) : (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <button
                                                            type="button"
                                                            className="row-menu"
                                                            aria-label={t((d) => d.common.actions)}
                                                            disabled={busy === subject.id}
                                                        >
                                                            <EllipsisVertical className="size-4" />
                                                        </button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-56">
                                                        <DropdownMenuItem
                                                            onSelect={() => {
                                                                setEditing(subject);
                                                            }}
                                                        >
                                                            <Pencil className="size-4" />
                                                            {t((d) => d.pages.settingsSubjects.edit)}
                                                        </DropdownMenuItem>

                                                        <DropdownMenuItem
                                                            onSelect={() => {
                                                                void changeStatus(subject, 'SUSPENDED');
                                                            }}
                                                        >
                                                            <PauseCircle className="size-4" />
                                                            {t((d) => d.pages.settingsSubjects.deactivate)}
                                                        </DropdownMenuItem>

                                                        <DropdownMenuSeparator />

                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onSelect={() => {
                                                                setClosing(subject);
                                                            }}
                                                        >
                                                            <XCircle className="size-4" />
                                                            {t((d) => d.pages.settingsSubjects.close)}
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {editing !== null && (
                <EditSubjectDialog
                    subject={editing}
                    onClose={() => {
                        setEditing(null);
                    }}
                    onSaved={() => {
                        setEditing(null);
                        setReloadToken((token) => token + 1);
                    }}
                    describeFailure={describeFailure}
                />
            )}

            {/* A confirmation, because closing is the one item here that a person cannot undo from
                this menu — a closed taxpayer is reactivated deliberately, not by clicking back. */}
            {closing !== null && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setClosing(null);
                        }
                    }}
                    title={t((d) => d.pages.settingsSubjects.closeTitle)}
                    description={t((d) => d.pages.settingsSubjects.closeHint)}
                    confirmLabel={t((d) => d.pages.settingsSubjects.close)}
                    cancelLabel={t((d) => d.pages.settingsSubjects.cancel)}
                    onConfirm={async () => {
                        await changeStatus(closing, 'CLOSED');
                    }}
                />
            )}

            <AddSubjectDialog
                open={adding}
                onOpenChange={setAdding}
                onAdded={() => {
                    setAdding(false);
                    setPage(null);
                    setPageNumber(1);
                    setReloadToken((token) => token + 1);
                }}
                describeFailure={describeFailure}
            />
        </AppShell>
    );
}
