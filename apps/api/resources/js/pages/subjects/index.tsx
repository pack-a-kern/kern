import { useCallback, useEffect, useState, type JSX } from 'react';
import {
    Banknote,
    EllipsisVertical,
    Info,
    Pencil,
    PlayCircle,
    RotateCcw,
    Plus,
    Users,
    UsersIcon,
    XCircle,
} from 'lucide-react';
import { toast } from 'sonner';

import { listSubjects, setDocumentAiConsent, setSubjectStatus } from '../../api/accountApi';
import { setDisplayCurrency } from '../../api/walletApi';
import {
    DataTable,
    EmptyState,
    Identity,
    Page,
    Panel,
    PanelButton,
    PanelSelect,
    Pill,
    Status,
} from '../../components/panel';
import Pagination from '../../components/Pagination';
import { Switch } from '../../components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { Skeleton } from '../../components/ui/skeleton';
import { useRememberedPaging } from '../../hooks/useRememberedState';
import { useCurrencies } from '../../lib/sharedProps';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import type { SubjectsPage, TaxSubject } from '../../types/account';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '../../components/ui/dropdown-menu';
import ConfirmDialog from '../../components/ConfirmDialog';
import { initialsFor } from '../../lib/initials';
import { subjectAvatarUrl } from '../../api/accountApi';
import AddSubjectDialog from './AddSubjectDialog';
import EditSubjectDialog from './EditSubjectDialog';

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
    const currencies = useCurrencies();

    const [page, setPage] = useState<SubjectsPage | null>(null);
    const { page: pageNumber, pageSize, setPage: setPageNumber, setPageSize } = useRememberedPaging('subjects');
    const [busy, setBusy] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloadToken, setReloadToken] = useState(0);
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<TaxSubject | null>(null);
    const [closing, setClosing] = useState<TaxSubject | null>(null);

    const describeFailure = useCallback((cause: unknown): string => describeApiFailure(cause, t), [t]);

    useEffect(() => {
        let active = true;

        // Each row carries its stored consent, so a switch never shows "off" for a consent that was
        // given — the exact misreading a consent control must not produce.
        listSubjects(pageNumber, pageSize)
            .then((loaded) => {
                if (active) {
                    setPage(loaded);

                    // A remembered page past the end of a list that has since shrunk lands on its last page.
                    if (loaded.data.length === 0 && loaded.total > 0) {
                        setPageNumber(Math.max(1, Math.ceil(loaded.total / loaded.perPage)));
                    }
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
    }, [reloadToken, pageNumber, pageSize, setPageNumber, describeFailure, t]);

    const toggleConsent = async (subject: TaxSubject, next: boolean): Promise<void> => {
        setBusy(subject.id);

        try {
            await setDocumentAiConsent(subject.id, next);
            setPage((current) =>
                current === null
                    ? null
                    : {
                          ...current,
                          data: current.data.map((row) => (row.id === subject.id ? { ...row, aiConsent: next } : row)),
                      },
            );
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

    const changeStatus = async (subject: TaxSubject, status: 'ACTIVE' | 'CLOSED'): Promise<void> => {
        setBusy(subject.id);

        try {
            await setSubjectStatus(subject.id, status);
            toast.success(t((d) => d.pages.settingsSubjects.statusChanged));

            // The list again, not a local patch of the row: the status decides what the row
            // offers. The switcher hears it on its own (`TaxpayersChanged`), as every other device of
            // every member does.
            setReloadToken((token) => token + 1);

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
        <>
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
                            <RotateCcw className="size-3.5" aria-hidden="true" />
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
                        // Not `inline`: that exists to match a 34px control the button stands beside,
                        // and this one stands alone in a card head. `.ctl-action`'s 30px is the size
                        // every other action in this card — the row menus, the reactivate button —
                        // already is.
                        <PanelButton
                            primary
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
                        <EmptyState
                            icon={<Users className="size-5" />}
                            title={t((d) => d.pages.settingsSubjects.empty)}
                        />
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
                                            avatar={
                                                subject.avatarUpdatedAt === null
                                                    ? undefined
                                                    : subjectAvatarUrl(subject.id, subject.avatarUpdatedAt)
                                            }
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
                                        {/* `select-compact`, not a width. A width here was doing
                                            nothing: `.select-trigger` sets `width: 100%` unlayered,
                                            and an unlayered rule beats a Tailwind utility whatever
                                            the specificity — so `w-24` never applied and the select
                                            filled the stacked row. `.select-compact` is the class
                                            written for this, and its comment names a currency. */}
                                        <PanelSelect
                                            className="select-compact"
                                            // One mark on the control, not one per option: it says
                                            // what this chooses, and repeating it down a list of
                                            // three-letter codes would distinguish nothing.
                                            icon={<Banknote className="size-3.5" aria-hidden="true" />}
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
                                            options={currencies.map((code) => ({ value: code, label: code }))}
                                        />
                                    </td>
                                    <td data-label={t((d) => d.pages.settingsSubjects.columns.ai)}>
                                        <Switch
                                            aria-label={`${t((d) => d.pages.settingsSubjects.columns.ai)} — ${subject.displayName}`}
                                            checked={subject.aiConsent}
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
                    // In place rather than by refetching, the same way the currency change is: the
                    // picture is already uploaded and accepted by the time this runs, and a refetch
                    // would close over the dialog the person is still standing in.
                    onAvatarChange={(subjectId, version) => {
                        setPage((current) =>
                            current === null
                                ? null
                                : {
                                      ...current,
                                      data: current.data.map((row) =>
                                          row.id === subjectId ? { ...row, avatarUpdatedAt: version } : row,
                                      ),
                                  },
                        );
                        setEditing((current) =>
                            current === null || current.id !== subjectId
                                ? current
                                : { ...current, avatarUpdatedAt: version },
                        );
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
        </>
    );
}
