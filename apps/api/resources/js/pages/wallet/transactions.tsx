import { useEffect, useMemo, useState, type JSX } from 'react';
import { toast } from 'sonner';

import {
    classifyTransaction,
    deleteTransaction,
    keepDuplicateTransactions,
    listAccounts,
    listBanks,
    listDuplicateTransactions,
    listTags,
    listTransactions,
    mergeDuplicateTransactions,
    recordTransaction,
    updateTransaction,
    type TransactionFilters,
} from '../../api/walletApi';
import {
    AlignLeftIcon,
    ArrowLeftRight,
    Check,
    Copy as CopyIcon,
    FilterX,
    Landmark,
    Pencil,
    PlusIcon,
    Shapes,
    Tag as TagIcon,
    Trash2,
    UserIcon,
    WalletCards,
    X,
} from 'lucide-react';
import AppShell from '../../components/AppShell';
import ConfirmDialog from '../../components/ConfirmDialog';
import {
    ButtonGroup,
    ActionGroup,
    DataTable,
    EmptyState,
    Field,
    FieldGrid,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelSelect,
    SearchInput,
    Pill,
    ToolbarRow,
    ToolbarRows,
} from '../../components/panel';
import BulkActions from '../../components/BulkActions';
import { PanelDate, PanelDateRange } from '../../components/PanelDatePicker';
import TagsInput from '../../components/wallet/TagsInput';
import Checkbox from '../../components/Checkbox';
import Pagination from '../../components/Pagination';
import { bankOptions } from '../../components/wallet/bankOptions';
import { DropdownMenuItem } from '../../components/ui/dropdown-menu';
import { Skeleton } from '../../components/ui/skeleton';
import { useRememberedPaging, useRememberedState } from '../../hooks/useRememberedState';
import { useRowSelection } from '../../hooks/useRowSelection';
import { useCountries } from './useCountries';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate } from '../../lib/format';
import { cn } from '../../lib/utils';
import { getActiveSubjectId } from '../../lib/subjectStore';
import {
    categoriesFor,
    categoryFor,
    signedMinor,
    type MoneyDirection,
    CURRENCIES,
    isStreamKind,
    sourceName,
    type AccountSummary,
    type Bank,
    type DuplicateGroup,
    type Tag,
    type TransactionPage,
    type TransactionSummary,
    bankLabel,
    formatMinor,
} from '../../types/wallet';

/**
 * Money moving through the accounts.
 *
 * Everything here is entered by hand today. The underlying table carries `import_batch_id` and a
 * blind-indexed external id because it was designed for bank-statement import, where a batch groups
 * one statement and the external id stops a re-import duplicating rows. That importer does not
 * exist, so `source` is always MANUAL.
 */
/**
 * The add/edit form's fields.
 *
 * Declared rather than inferred: `CURRENCIES[0]` is a const-asserted tuple, so inference would pin
 * `currency_code` to the literal 'RON' and reject every other code the select offers.
 */
interface TransactionForm {
    account_id: string;
    direction: MoneyDirection;
    amount: string;
    currency_code: string;
    booked_at: string;
    description: string;
    counterparty: string;
    category_code: string;
    country_code: string;
    /** Where it happened. '' is "no bank", which is a real answer. */
    bank_id: string;
    /** The person's own labels, by name: typing one that does not exist creates it. */
    tags: string[];
}

function emptyTransactionForm(): TransactionForm {
    return {
        account_id: '',
        // Money in: what a tax return reads, and never given a category until someone chooses one.
        direction: 'in',
        amount: '',
        currency_code: CURRENCIES[0],
        booked_at: '',
        description: '',
        counterparty: '',
        category_code: '',
        // Romania: the answer almost every time, and the absence of a country when stored.
        country_code: 'RO',
        bank_id: '',
        tags: [],
    };
}

export default function WalletTransactions(): JSX.Element {
    const { t, locale } = useTranslation();
    const countries = useCountries();
    const subjectId = getActiveSubjectId();

    /** Keyed by the query it answers, so a page never shows rows fetched for other filters. */
    const [loaded, setLoaded] = useState<{ key: string; page: TransactionPage } | null>(null);
    const [accounts, setAccounts] = useState<AccountSummary[]>([]);
    const [banks, setBanks] = useState<Bank[]>([]);
    const [tags, setTags] = useState<Tag[]>([]);
    /** The transaction being corrected, or null when the dialog is recording a new one. */
    const [editing, setEditing] = useState<TransactionSummary | null>(null);
    /**
     * Which table this screen is showing. `duplicates` used to be a fourth option in the status
     * select beside `all`, `unclassified` and `classified` — and it is not a status and not a filter:
     * it replaces the table with lookalike groups, answered by its own endpoint, and every filter
     * beside it stops applying. A person choosing it from a list of statuses had no way to know that.
     */
    const [view, setView] = useRememberedState<string>('wallet.transactions.view', 'ledger', {
        perSubject: true,
        isValid: (value) => ['ledger', 'duplicates'].includes(value),
    });
    /**
     * What tax answer a row carries: `all`, `unclassified`, or one category code.
     *
     * One control rather than two, because they are one question. "Classified" as its own option was
     * dropped: it named a state nobody acts on — a person looking for what is already answered is
     * looking for rent, or for dividends, and now asks for that instead.
     *
     * Validated loosely here and strictly on the server, which checks the code against the same
     * catalogue the classify dialog is built from. A code retired between two visits would otherwise
     * sit in a remembered filter nothing could satisfy.
     */
    const [classification, setClassification] = useRememberedState<string>(
        'wallet.transactions.classification',
        'all',
        {
            perSubject: true,
            isValid: (value) => value !== '',
        },
    );
    const {
        page: pageNumber,
        pageSize,
        setPage: setPageNumber,
        setPageSize,
    } = useRememberedPaging('wallet.transactions', {
        perSubject: true,
    });
    const [bulkDeleting, setBulkDeleting] = useState(false);
    const [confirmingBulkDelete, setConfirmingBulkDelete] = useState(false);
    const [accountId, setAccountId] = useRememberedState<string>('wallet.transactions.account', '', {
        perSubject: true,
    });
    const [bankId, setBankId] = useRememberedState<string>('wallet.transactions.bank', '', { perSubject: true });
    const [tagId, setTagId] = useRememberedState<string>('wallet.transactions.tag', '', { perSubject: true });
    const [direction, setDirection] = useRememberedState<string>('wallet.transactions.direction', '', {
        perSubject: true,
        isValid: (value) => ['', 'IN', 'OUT'].includes(value),
    });
    /**
     * What has been typed into the counterparty box.
     *
     * The text itself, because that is what filters: the server matches it against its decrypted
     * directory and narrows by every counterparty it finds. Debounced below, so a keystroke is not a
     * query.
     */
    const [counterparty, setCounterparty] = useRememberedState<string>('wallet.transactions.counterparty', '', {
        perSubject: true,
    });
    /**
     * What the box shows while the debounce runs — the filter lags it by a moment.
     *
     * Started from the remembered filter, not from empty. Empty meant that on every reload the box
     * disagreed with the filter it belonged to, and the debounce below resolved that disagreement
     * the wrong way: three hundred milliseconds after the page loaded, it wrote the empty box over
     * the saved search. Every other filter on this screen survived a refresh and this one erased
     * itself.
     */
    const [counterpartyTyped, setCounterpartyTyped] = useState(counterparty);
    const [from, setFrom] = useRememberedState<string>('wallet.transactions.from', '', { perSubject: true });
    const [to, setTo] = useRememberedState<string>('wallet.transactions.to', '', { perSubject: true });
    const [failed, setFailed] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    /**
     * Lookalike groups, tagged with the load they belong to — the same pattern as `loaded` above, so
     * a merge that triggers a reload never shows the set that was just merged.
     */
    const [loadedDuplicates, setLoadedDuplicates] = useState<{ reloadKey: number; groups: DuplicateGroup[] } | null>(
        null,
    );
    /** The group waiting for a person to confirm a merge. Merging deletes rows, so it is asked. */
    const [merging, setMerging] = useState<DuplicateGroup | null>(null);

    /**
     * Whether anything is narrowing the list. Drives the reset button, which otherwise sat in the row
     * permanently offering to undo nothing.
     */
    const filtersSet =
        accountId !== '' ||
        bankId !== '' ||
        tagId !== '' ||
        counterparty !== '' ||
        direction !== '' ||
        from !== '' ||
        to !== '' ||
        classification !== 'all';

    /**
     * What is filtering, as chips that come off where they are read.
     *
     * Built from the filters rather than kept beside them, so a filter cleared by its own control
     * cannot leave a chip behind. Each one is labelled by what it narrows: a bank and a tag both read
     * as a word, and "Salt Bank" alone does not say which of the two it came from.
     */
    const activeFilters: Array<{ key: string; label: string; value: string; clear: () => void }> = [];

    const chip = (key: string, label: string, value: string, clear: () => void): void => {
        activeFilters.push({
            key,
            label,
            value,
            clear: () => {
                narrow(clear);
            },
        });
    };

    /**
     * Any narrowing starts again from the first page: staying on page 7 of a list that now has two
     * pages shows an empty table and looks like the filter found nothing.
     */
    const narrow = (apply: () => void): void => {
        apply();
        setPageNumber(1);
    };

    /*
     * The typed text becomes the filter after a pause, not on every keystroke.
     *
     * Each query decrypts this taxpayer's whole counterparty directory to match a fragment, so a
     * request per letter is a directory decrypted per letter. 300ms is long enough that typing a
     * word costs one query and short enough that it still feels like typing.
     */
    useEffect(() => {
        if (counterpartyTyped === counterparty) {
            return;
        }

        const timer = window.setTimeout(() => {
            narrow(() => {
                setCounterparty(counterpartyTyped);
            });
        }, 300);

        return () => {
            window.clearTimeout(timer);
        };
        // On the typed text alone: `narrow` and the setters are new functions on every render, and
        // this effect causes renders.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [counterpartyTyped]);

    if (accountId !== '') {
        chip(
            'account',
            t((d) => d.pages.walletTransactions.filters.labelAccount),
            sourceName(
                accounts.find((account) => account.id === accountId) ?? { kind: 'GENERAL', displayName: '' },
                t((d) => d.pages.wallet.generalSource),
            ),
            () => {
                setAccountId('');
            },
        );
    }

    if (bankId !== '') {
        const bank = banks.find((candidate) => candidate.id === bankId);

        chip(
            'bank',
            t((d) => d.pages.walletTransactions.filters.labelBank),
            bank === undefined ? '' : bankLabel(bank),
            () => {
                setBankId('');
            },
        );
    }

    if (counterparty !== '') {
        chip(
            'counterparty',
            t((d) => d.pages.walletTransactions.filters.counterparty),
            counterparty,
            () => {
                setCounterparty('');
                setCounterpartyTyped('');
            },
        );
    }

    if (tagId !== '') {
        chip(
            'tag',
            t((d) => d.pages.walletTransactions.filters.labelTag),
            tags.find((tag) => tag.id === tagId)?.name ?? '',
            () => {
                setTagId('');
            },
        );
    }

    if (direction !== '') {
        chip(
            'direction',
            t((d) => d.pages.walletTransactions.filters.labelDirection),
            t((d) =>
                direction === 'OUT'
                    ? d.pages.walletTransactions.filters.directionOut
                    : d.pages.walletTransactions.filters.directionIn,
            ),
            () => {
                setDirection('');
            },
        );
    }

    if (classification !== 'all') {
        chip(
            'classification',
            t((d) => d.pages.walletTransactions.filters.labelStatus),
            classification === 'unclassified'
                ? t((d) => d.pages.walletTransactions.classify.unclassified)
                : t((d) => d.pages.walletTransactions.categories[classification] ?? classification),
            () => {
                setClassification('all');
            },
        );
    }

    if (from !== '' || to !== '') {
        chip(
            'dates',
            t((d) => d.pages.walletTransactions.filters.labelDates),
            [from, to].filter((part) => part !== '').join(' — '),
            () => {
                setFrom('');
                setTo('');
            },
        );
    }

    const resetFilters = (): void => {
        narrow(() => {
            setAccountId('');
            setBankId('');
            setTagId('');
            setCounterparty('');
            setCounterpartyTyped('');
            setDirection('');
            setFrom('');
            setTo('');
            setClassification('all');
        });
    };

    const errors = useFormErrors();
    const [adding, setAdding] = useState(false);
    const [deleting, setDeleting] = useState<TransactionSummary | null>(null);
    const [saving, setSaving] = useState(false);
    const [form, setForm] = useState<TransactionForm>(emptyTransactionForm());

    /*
     * What the server is asked for. `classification` is sent as the stored status rather than the
     * screen's own word for it.
     */
    const filters: TransactionFilters = useMemo(
        () => ({
            ...(accountId === '' ? {} : { accountId }),
            ...(bankId === '' ? {} : { bankId }),
            ...(tagId === '' ? {} : { tagId }),
            ...(counterparty === '' ? {} : { counterparty }),
            ...(classification === 'unclassified' ? { classification: 'UNCLASSIFIED' } : {}),
            // Anything that is neither of the two words is a category code, which the server checks
            // against its catalogue before it reaches a query.
            ...(classification === 'all' || classification === 'unclassified' ? {} : { categoryCode: classification }),
            ...(direction === '' ? {} : { direction }),
            ...(from === '' ? {} : { from }),
            ...(to === '' ? {} : { to }),
            page: pageNumber,
            perPage: pageSize,
        }),
        [accountId, bankId, tagId, counterparty, classification, direction, from, to, pageNumber, pageSize],
    );
    const filterKey = JSON.stringify(filters);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;

        listTransactions(subjectId, filters).then(
            (page) => {
                if (active) {
                    setLoaded({ key: filterKey, page });
                    setFailed(false);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                }
            },
        );

        // The bank list and the taxpayer's tags, for the filters, the form and the chips on each row.
        listBanks(subjectId, { includeClosed: true }).then(
            (loadedBanks) => {
                if (active) {
                    setBanks(loadedBanks);
                }
            },
            () => {
                if (active) {
                    setBanks([]);
                }
            },
        );

        listTags(subjectId).then(
            (loadedTags) => {
                if (active) {
                    setTags(loadedTags);

                    // A remembered filter naming a tag since deleted or merged away would narrow the
                    // list to nothing under a select reading "all tags".
                    if (tagId !== '' && !loadedTags.some((tag) => tag.id === tagId)) {
                        setTagId('');
                    }
                }
            },
            () => {
                if (active) {
                    setTags([]);
                }
            },
        );

        // Every source, for the filter: a deposit's interest and a cash declaration are transactions
        // too, and a filter that cannot reach them hides them. The form narrows this to the sources
        // that receive transactions — see `streamAccounts`.
        listAccounts(subjectId).then(
            (page) => {
                if (active) {
                    setAccounts(page.accounts);

                    // A remembered filter naming an account since deleted would filter to nothing under
                    // a select that reads "all accounts".
                    if (accountId !== '' && !page.accounts.some((account) => account.id === accountId)) {
                        setAccountId('');
                    }
                }
            },
            () => {
                if (active) {
                    setAccounts([]);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, accountId, filterKey, filters, reloadKey, setAccountId, tagId, setTagId]);

    // Only while the duplicates view is open: it decrypts every candidate row's counterparty, which
    // is work nobody asked for while reading the ordinary list.
    useEffect(() => {
        if (subjectId === null || view !== 'duplicates') {
            return;
        }

        let active = true;

        listDuplicateTransactions(subjectId).then(
            (groups) => {
                if (active) {
                    setLoadedDuplicates({ reloadKey, groups });
                }
            },
            () => {
                if (active) {
                    setLoadedDuplicates({ reloadKey, groups: [] });
                    toast.error(t((d) => d.pages.walletTransactions.duplicates.failed));
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, view, reloadKey, t]);

    const page = loaded !== null && loaded.key === filterKey ? loaded.page : null;
    const duplicates =
        loadedDuplicates !== null && loadedDuplicates.reloadKey === reloadKey ? loadedDuplicates.groups : null;

    // What a new transaction can be recorded against. A cash holding is one declared value and a
    // deposit's entries come from its terms, so the server refuses either — and the form does not ask.
    const streamAccounts = accounts.filter((account) => isStreamKind(account.kind));
    // A deposit's funding, interest or settlement. Shown, never changed: the deposit rewrites it.
    const isSystem = editing?.source === 'SYSTEM';

    // No confirmation, unlike a merge: keeping deletes nothing, and a group kept by mistake comes
    // back the moment another identical payment arrives.
    const keepGroup = async (group: DuplicateGroup): Promise<void> => {
        if (subjectId === null) {
            return;
        }

        try {
            await keepDuplicateTransactions(
                subjectId,
                group.transactions.map((transaction) => transaction.id),
            );
            toast.success(t((d) => d.pages.walletTransactions.duplicates.kept));
            setReloadKey((current) => current + 1);
        } catch {
            toast.error(t((d) => d.pages.walletTransactions.duplicates.failed));
        }
    };

    // The rows on this page, as the server selected and ordered them.
    const rows = useMemo(() => page?.transactions ?? [], [page]);
    // A deposit's own rows are never selectable: bulk delete would be refused for them, and a tick
    // that can only lead to an error is a control that lies about what is possible.
    const pageIds = useMemo(
        () => rows.filter((transaction) => transaction.source !== 'SYSTEM').map((transaction) => transaction.id),
        [rows],
    );
    const selection = useRowSelection(pageIds);

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.walletTransactions.title)}>
                    <Panel title={t((d) => d.pages.walletTransactions.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    const deleteSelected = async (): Promise<void> => {
        setBulkDeleting(true);

        let removed = 0;

        try {
            // One request each: there is no bulk endpoint, and inventing one would skip the audit
            // event every single delete already writes. Sequential so a partial failure leaves a
            // coherent list rather than an unknown number of rows gone.
            for (const id of selection.ids) {
                await deleteTransaction(subjectId, id);
                removed += 1;
            }

            toast.success(t((d) => d.pages.walletTransactions.deleted));
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.common.deleteFailed),
                { description: describeApiFailure(cause, t) },
            );
        } finally {
            setBulkDeleting(false);
            setConfirmingBulkDelete(false);

            if (removed > 0) {
                selection.clear();
                setReloadKey((current) => current + 1);
            }
        }
    };

    const submit = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            if (editing !== null) {
                // Correcting one. The source is absent on purpose: a transaction recorded against the
                // wrong source is a different transaction, and moving it would reinterpret a balance
                // that has already been read. The currency is not — a payment typed in the wrong
                // currency is the same payment with a mistake in it.
                await updateTransaction(subjectId, editing.id, {
                    amount_minor: signedMinor(form.amount, form.direction),
                    currency_code: form.currency_code,
                    booked_at: form.booked_at,
                    description: form.description,
                    counterparty: form.counterparty === '' ? null : form.counterparty,
                    bank_id: form.bank_id === '' ? null : form.bank_id,
                    tags: form.tags,
                });

                // And what it was, in the same save. Two endpoints because the transaction and its
                // answer are different records, but one action as far as a person is concerned —
                // the alternative was a second button that opened a second dialog to change one
                // field the first dialog was already showing.
                await classifyTransaction(
                    subjectId,
                    editing.id,
                    form.category_code === ''
                        ? []
                        : [
                              {
                                  amount_minor: signedMinor(form.amount, form.direction),
                                  category_code: form.category_code,
                                  country_code: form.country_code === 'RO' ? null : form.country_code,
                              },
                          ],
                );

                toast.success(t((d) => d.pages.walletTransactions.saved));
                setEditing(null);
                setForm(emptyTransactionForm());
                setReloadKey((current) => current + 1);

                return;
            }

            await recordTransaction(subjectId, {
                account_id: form.account_id,
                // Signed minor units: the amount is typed without a sign and the toggle supplies it,
                // the same as the document import dialog.
                amount_minor: signedMinor(form.amount, form.direction),
                currency_code: form.currency_code,
                booked_at: form.booked_at,
                description: form.description,
                ...(form.counterparty === '' ? {} : { counterparty: form.counterparty }),
                // Answered here rather than in a second dialog afterwards: someone typing a
                // transaction already knows what it was. Romania is the absence of a country.
                ...(form.category_code === '' ? {} : { category_code: form.category_code }),
                ...(form.category_code === '' || form.country_code === 'RO' ? {} : { country_code: form.country_code }),
                ...(form.bank_id === '' ? {} : { bank_id: form.bank_id }),
                ...(form.tags.length === 0 ? {} : { tags: form.tags }),
            });

            toast.success(t((d) => d.pages.walletTransactions.added));
            setAdding(false);
            setReloadKey((current) => current + 1);
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                toast.error(
                    editing === null
                        ? t((d) => d.pages.walletTransactions.addFailed)
                        : t((d) => d.pages.walletTransactions.saveFailed),
                );
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.walletTransactions.title)}
                lede={t((d) => d.pages.walletTransactions.description)}
            >
                <Panel
                    title={t((d) => d.pages.walletTransactions.title)}
                    description={t((d) => d.pages.walletTransactions.description)}
                    icon={<ArrowLeftRight className="size-4" />}
                    flush
                    aside={
                        <>
                            {/* Up here with the other thing that changes what the screen is, rather
                                than among the filters — it is not one. Importing moved out: it has
                                its own place in the sidebar, and two buttons that both leave this
                                screen made the one people press look like the one they do not. */}
                            <PanelButton
                                inline
                                variant={view === 'duplicates' ? 'outline' : 'ghost'}
                                className={view === 'duplicates' ? 'bg-secondary' : ''}
                                aria-pressed={view === 'duplicates'}
                                onClick={() => {
                                    narrow(() => {
                                        setView(view === 'duplicates' ? 'ledger' : 'duplicates');
                                    });
                                }}
                            >
                                <CopyIcon className="size-3.5" aria-hidden="true" />
                                {/* Named for where it goes, not for where you are. In the duplicates
                                    view it is the only way back, and a button still reading
                                    "Duplicate" is one that looks like it would do it again. */}
                                {t((d) =>
                                    view === 'duplicates'
                                        ? d.pages.walletTransactions.toolbar.ledger
                                        : d.pages.walletTransactions.duplicates.filter,
                                )}
                            </PanelButton>
                            <PanelButton
                                primary
                                inline
                                disabled={accounts.length === 0}
                                onClick={() => {
                                    errors.reset();
                                    setForm({
                                        ...emptyTransactionForm(),
                                        account_id: accounts[0]?.id ?? '',
                                        currency_code: accounts[0]?.currencyCode ?? CURRENCIES[0],
                                        booked_at: new Date().toISOString().slice(0, 10),
                                    });
                                    setAdding(true);
                                }}
                            >
                                <PlusIcon className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletTransactions.add)}
                            </PanelButton>
                        </>
                    }
                    foot={
                        /* Nothing to page in the duplicates view: it asks its own endpoint, which
                           returns every group it found. Left in place it reported the ledger's own
                           total under an empty list — "1–25 of 594" above "No duplicate
                           transactions". */
                        view === 'duplicates' ? undefined : (
                            <Pagination
                                page={pageNumber}
                                pageSize={pageSize}
                                total={page?.total ?? 0}
                                unit={t((d) => d.pages.walletTransactions.unit)}
                                onPageChange={setPageNumber}
                                onPageSizeChange={(size) => {
                                    setPageSize(size);
                                    setPageNumber(1);
                                }}
                            />
                        )
                    }
                >
                    {/*
                        One line that is always there, and five filters that are not.

                        Nine controls in a row made every one of them look equally important, and the
                        two people reach for — what was it, and when — were lost among the five they
                        set once a month. So the search and the dates stay out, and the rest fold
                        behind a button that says how many of them are on.
                    */}
                    {/* Gone entirely while duplicates are shown. None of it applies there — that
                        view asks its own endpoint and ignores every filter — and a row left behind
                        saying so is still a row to read past. The way back is the button in the
                        header, which names where it goes. */}
                    {view === 'duplicates' ? null : (
                        <ToolbarRows>
                            <ToolbarRow
                                end={
                                    <>
                                        <BulkActions count={selection.size} disabled={bulkDeleting}>
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() => {
                                                    setConfirmingBulkDelete(true);
                                                }}
                                            >
                                                <Trash2 className="size-4" />
                                                {t((d) => d.common.deleteSelected)}
                                            </DropdownMenuItem>
                                        </BulkActions>
                                    </>
                                }
                            >
                                <SearchInput
                                    value={counterpartyTyped}
                                    placeholder={t((d) => d.pages.walletTransactions.filters.counterparty)}
                                    onChange={setCounterpartyTyped}
                                />
                                <PanelDateRange
                                    disableFuture
                                    value={{ from: from === '' ? null : from, to: to === '' ? null : to }}
                                    onChange={(next) => {
                                        narrow(() => {
                                            setFrom(next.from ?? '');
                                            setTo(next.to ?? '');
                                        });
                                    }}
                                />
                            </ToolbarRow>

                            {/* Always open. It was behind a button with a count on it, which meant a
                                second row of chips to say what that count stood for — two pieces of
                                furniture to explain a panel that fits on one line. */}
                            <ToolbarRow>
                                <div className="filter-card">
                                    {/* Labelled and equal width: five unlabelled selects in a row
                                            are five things to identify by their placeholder, and
                                            "Toate" appears in three of them. */}
                                    <div className="filter-grid">
                                        <Field label={t((d) => d.pages.walletTransactions.filters.labelAccount)}>
                                            <PanelSelect
                                                className="w-full"
                                                icon={<WalletCards className="size-3.5" aria-hidden="true" />}
                                                value={accountId}
                                                onValueChange={(next) => {
                                                    narrow(() => {
                                                        setAccountId(next);
                                                    });
                                                }}
                                                placeholder={t((d) => d.pages.walletTransactions.allAccounts)}
                                                emptyLabel={t((d) => d.pages.walletTransactions.allAccounts)}
                                                /*
                                            The sources with rows in this view, not every source the taxpayer
                                            has. A cash holding's rows are never listed and a term deposit's are
                                            not listed until interest is posted, so both were choices that
                                            answered "no transactions". Narrowed by the other filters and not by
                                            this one, and the chosen source is kept whatever the list says —
                                            exactly as for the banks beside it.
                                            */
                                                options={accounts
                                                    .filter(
                                                        (account) =>
                                                            account.id === accountId ||
                                                            (page?.accountIdsInUse ?? []).includes(account.id),
                                                    )
                                                    .map((account) => ({
                                                        value: account.id,
                                                        label: sourceName(
                                                            account,
                                                            t((d) => d.pages.wallet.generalSource),
                                                        ),
                                                    }))}
                                            />
                                        </Field>
                                        <Field label={t((d) => d.pages.walletTransactions.filters.labelBank)}>
                                            <PanelSelect
                                                className="w-full"
                                                icon={<Landmark className="size-3.5" aria-hidden="true" />}
                                                searchable
                                                value={bankId}
                                                placeholder={t((d) => d.pages.walletTransactions.filters.allBanks)}
                                                emptyLabel={t((d) => d.pages.walletTransactions.filters.allBanks)}
                                                onValueChange={(next) => {
                                                    narrow(() => {
                                                        setBankId(next);
                                                    });
                                                }}
                                                optionVisual="bare"
                                                /*
                                            The banks reachable under the other filters, not the register.
                                            Offering all forty-six meant scrolling past forty-odd choices that
                                            each return nothing, and a filter should never offer an empty result.
                                            The server narrows this by every filter except the bank itself, so
                                            choosing a bank never leaves it as the only bank left to choose.

                                            The chosen one is kept whatever the list says, because the list is
                                            free to drop it: pick a bank, then a tag it has no rows under, and
                                            the select would otherwise hold a value it cannot show a name for.
                                            */
                                                options={bankOptions(
                                                    banks.filter(
                                                        (bank) =>
                                                            bank.id === bankId ||
                                                            (page?.bankIdsInUse ?? []).includes(bank.id),
                                                    ),
                                                    t((d) => d.pages.walletTransactions.bank.closed),
                                                )}
                                            />
                                        </Field>
                                        <Field label={t((d) => d.pages.walletTransactions.filters.labelTag)}>
                                            <PanelSelect
                                                className="w-full"
                                                icon={<TagIcon className="size-3.5" aria-hidden="true" />}
                                                searchable
                                                value={tagId}
                                                placeholder={t((d) => d.pages.walletTransactions.filters.allTags)}
                                                emptyLabel={t((d) => d.pages.walletTransactions.filters.allTags)}
                                                onValueChange={(next) => {
                                                    narrow(() => {
                                                        setTagId(next);
                                                    });
                                                }}
                                                options={tags.map((tag) => ({ value: tag.id, label: tag.name }))}
                                            />
                                        </Field>
                                        <Field label={t((d) => d.pages.walletTransactions.filters.labelDirection)}>
                                            <PanelSelect
                                                className="w-full"
                                                icon={<ArrowLeftRight className="size-3.5" aria-hidden="true" />}
                                                value={direction}
                                                placeholder={t((d) => d.pages.walletTransactions.filters.allDirections)}
                                                emptyLabel={t((d) => d.pages.walletTransactions.filters.allDirections)}
                                                onValueChange={(next) => {
                                                    narrow(() => {
                                                        setDirection(next);
                                                    });
                                                }}
                                                options={[
                                                    {
                                                        value: 'IN',
                                                        label: t((d) => d.pages.walletTransactions.filters.directionIn),
                                                    },
                                                    {
                                                        value: 'OUT',
                                                        label: t(
                                                            (d) => d.pages.walletTransactions.filters.directionOut,
                                                        ),
                                                    },
                                                ]}
                                            />
                                        </Field>
                                        <Field label={t((d) => d.pages.walletTransactions.filters.labelStatus)}>
                                            <PanelSelect
                                                className="w-full"
                                                icon={<Shapes className="size-3.5" aria-hidden="true" />}
                                                value={classification}
                                                onValueChange={(next) => {
                                                    narrow(() => {
                                                        setClassification(next === '' ? 'all' : next);
                                                    });
                                                }}
                                                options={[
                                                    {
                                                        value: 'all',
                                                        label: t(
                                                            (d) => d.pages.walletTransactions.classify.allStatuses,
                                                        ),
                                                    },
                                                    {
                                                        value: 'unclassified',
                                                        label: t(
                                                            (d) => d.pages.walletTransactions.classify.unclassified,
                                                        ),
                                                    },
                                                    // The catalogue the classify dialog offers, so
                                                    // the filter cannot name a category that no
                                                    // transaction could have been given. It
                                                    // replaces a "classified" option, which named
                                                    // a state nobody acts on: a person looking for
                                                    // what is answered is looking for rent, or for
                                                    // dividends, and now asks for that.
                                                    ...(page?.categories ?? []).map((category) => ({
                                                        value: category.code,
                                                        label: t(
                                                            (d) =>
                                                                d.pages.walletTransactions.categories[category.code] ??
                                                                category.code,
                                                        ),
                                                    })),
                                                ]}
                                            />
                                        </Field>
                                    </div>
                                    {filtersSet ? (
                                        <div className="filter-card-foot">
                                            <div className="filter-card-chips">
                                                {activeFilters.map((filter) => (
                                                    <button
                                                        key={filter.key}
                                                        type="button"
                                                        className="filter-chip"
                                                        onClick={filter.clear}
                                                        title={t((d) => d.pages.walletTransactions.filters.remove)}
                                                    >
                                                        <span className="truncate">
                                                            <span className="font-normal opacity-70">
                                                                {filter.label}:{' '}
                                                            </span>
                                                            {filter.value}
                                                        </span>
                                                        <X className="size-3 shrink-0" aria-hidden="true" />
                                                    </button>
                                                ))}
                                            </div>
                                            <PanelButton onClick={resetFilters}>
                                                <FilterX className="size-3.5" aria-hidden="true" />
                                                {t((d) => d.pages.walletTransactions.filters.reset)}
                                            </PanelButton>
                                        </div>
                                    ) : null}
                                </div>
                            </ToolbarRow>
                        </ToolbarRows>
                    )}

                    {view === 'duplicates' ? (
                        duplicates === null ? (
                            <TableSkeleton />
                        ) : duplicates.length === 0 ? (
                            <EmptyState
                                icon={<ArrowLeftRight className="size-5" />}
                                title={t((d) => d.pages.walletTransactions.duplicates.empty)}
                                description={t((d) => d.pages.walletTransactions.duplicates.hint)}
                            />
                        ) : (
                            <DuplicateGroups
                                groups={duplicates}
                                onMerge={setMerging}
                                onKeep={(group) => {
                                    void keepGroup(group);
                                }}
                            />
                        )
                    ) : page === null ? (
                        failed ? (
                            <p className="note p-4">{t((d) => d.pages.wallet.loadFailed)}</p>
                        ) : (
                            <TableSkeleton />
                        )
                    ) : rows.length === 0 ? (
                        <EmptyState
                            icon={<ArrowLeftRight className="size-5" />}
                            title={t((d) => d.pages.walletTransactions.emptyTitle)}
                            description={t((d) => d.pages.walletTransactions.emptyHint)}
                        />
                    ) : (
                        <DataTable
                            stacked
                            head={
                                <>
                                    <th className="w-8">
                                        <Checkbox
                                            checked={selection.allOnPage}
                                            indeterminate={selection.someOnPage}
                                            onCheckedChange={selection.toggleAll}
                                            aria-label={t((d) => d.common.selectPage)}
                                        />
                                    </th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.date)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.account)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.description)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.counterparty)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.amount)}</th>
                                    <th>{t((d) => d.pages.walletTransactions.columns.status)}</th>
                                    <th />
                                </>
                            }
                        >
                            {rows.map((transaction) => (
                                <tr
                                    key={transaction.id}
                                    className={cn(selection.has(transaction.id) && 'row-selected')}
                                >
                                    <td className="col-hide w-8">
                                        {transaction.source === 'SYSTEM' ? null : (
                                            <Checkbox
                                                checked={selection.has(transaction.id)}
                                                onCheckedChange={(next) => {
                                                    selection.toggle(transaction.id, next);
                                                }}
                                                aria-label={transaction.description}
                                            />
                                        )}
                                    </td>
                                    <td className="col-primary whitespace-nowrap tabular-nums">
                                        {formatDate(transaction.bookedAt, locale)}
                                    </td>
                                    {/* The account, and beneath it the bank. Both answer "where", and
                                        the bank was down in the description cell answering it from
                                        under the counterparty and the tags. */}
                                    <td className="col-hide">
                                        <div className="truncate">
                                            {sourceName(
                                                {
                                                    kind: transaction.accountKind,
                                                    displayName: transaction.accountName,
                                                },
                                                t((d) => d.pages.wallet.generalSource),
                                            )}
                                        </div>
                                        {transaction.bankName === null ? null : (
                                            <div className="note inline-flex items-center gap-1">
                                                <Landmark className="size-3 shrink-0" aria-hidden="true" />
                                                <span className="truncate">{transaction.bankName}</span>
                                            </div>
                                        )}
                                    </td>
                                    {/* What the bank called it, and what the person called it.
                                        This cell used to hold four stacked things — the text, the
                                        counterparty, the bank and the tags — with one header above
                                        them saying "Description", so nothing said which line was
                                        which. Three of them now sit under headers of their own. */}
                                    <td className="col-wide">
                                        <div>{transaction.description}</div>
                                        {/* The person's own labels stay here: a tag describes the
                                            payment, not who was paid, and "how much on food" is a
                                            question about this text rather than about the payee. */}
                                        {transaction.tags.length === 0 ? null : (
                                            <div className="mt-1 flex flex-wrap items-center gap-1">
                                                {transaction.tags.map((tag) => (
                                                    /* Same tone as a category now, so the mark is
                                                       what tells them apart rather than the colour —
                                                       a grey pill beside a coloured one read as the
                                                       lesser of the two, which a tag is not. */
                                                    <Pill key={tag.id} tone="accent">
                                                        <TagIcon aria-hidden="true" />
                                                        {tag.name}
                                                    </Pill>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td data-label={t((d) => d.pages.walletTransactions.columns.counterparty)}>
                                        {transaction.counterparty === null ? (
                                            // An em dash rather than nothing: an empty cell in a
                                            // column of names reads as a rendering fault.
                                            <span className="note">—</span>
                                        ) : (
                                            <div className="cell-name" title={transaction.counterparty}>
                                                {transaction.counterparty}
                                            </div>
                                        )}
                                    </td>
                                    {/* Money out reads red; the sign is the source of truth, not a
                                        separate direction column that could contradict it. */}
                                    <td
                                        className={`tabular-nums whitespace-nowrap ${
                                            transaction.amountMinor < 0 ? 'is-out' : 'is-in'
                                        }`}
                                    >
                                        {formatMinor(transaction.amountMinor, transaction.currencyCode, locale)}
                                    </td>
                                    <td data-label={t((d) => d.pages.walletTransactions.columns.status)}>
                                        {/* The answer itself, not merely that one exists: this list
                                            is the worklist, and "Chirii · DE" is what a person is
                                            checking for. An unanswered row stays a plain pill so it
                                            reads as the thing still to do. */}
                                        {transaction.splits.length === 0 ? (
                                            <Pill>{t((d) => d.pages.walletTransactions.classify.unclassified)}</Pill>
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {transaction.splits.map((split, index) => (
                                                    <Pill key={index} tone="accent">
                                                        <Shapes aria-hidden="true" />
                                                        {t(
                                                            (d) =>
                                                                d.pages.walletTransactions.categories[
                                                                    split.categoryCode
                                                                ] ?? split.categoryCode,
                                                        )}
                                                        {split.countryCode === null ? '' : ` · ${split.countryCode}`}
                                                    </Pill>
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td className="col-end text-right">
                                        {/* One group rather than two loose buttons, as on accounts,
                                            imports and the document inbox: a row's actions are one
                                            object, and two buttons with a gap between them read as
                                            two unrelated ones.

                                            Icons rather than words: "Modifică | Șterge" repeated down
                                            forty rows was the widest thing on screen saying the least.
                                            `title` carries the name for a pointer and, as the group's
                                            `aria-label`, for a reader.

                                            Edit is one action because there is one thing to change:
                                            the figures and what the money was are the same dialog. A
                                            separate "classify" opened a second dialog to edit one
                                            field the first was already showing. */}
                                        <div className="flex justify-end">
                                            <ActionGroup
                                                actions={[
                                                    {
                                                        key: 'edit',
                                                        label: <Pencil className="size-3.5" aria-hidden="true" />,
                                                        title: t((d) => d.common.edit),
                                                        onClick: () => {
                                                            errors.reset();
                                                            setForm({
                                                                account_id: transaction.accountId,
                                                                direction: transaction.amountMinor < 0 ? 'out' : 'in',
                                                                amount: String(Math.abs(transaction.amountMinor) / 100),
                                                                currency_code: transaction.currencyCode,
                                                                booked_at: transaction.bookedAt.slice(0, 10),
                                                                description: transaction.description,
                                                                counterparty: transaction.counterparty ?? '',
                                                                category_code:
                                                                    transaction.splits[0]?.categoryCode ?? '',
                                                                country_code:
                                                                    transaction.splits[0]?.countryCode ?? 'RO',
                                                                bank_id: transaction.bankId ?? '',
                                                                tags: transaction.tags.map((tag) => tag.name),
                                                            });
                                                            setEditing(transaction);
                                                        },
                                                    },
                                                    // A deposit's own movements are rewritten by the
                                                    // deposit, so there is nothing here to delete.
                                                    ...(transaction.source === 'SYSTEM'
                                                        ? []
                                                        : [
                                                              {
                                                                  key: 'delete',
                                                                  label: (
                                                                      <Trash2 className="size-3.5" aria-hidden="true" />
                                                                  ),
                                                                  title: t((d) => d.common.delete),
                                                                  tone: 'destructive' as const,
                                                                  onClick: () => {
                                                                      setDeleting(transaction);
                                                                  },
                                                              },
                                                          ]),
                                                ]}
                                            />
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </DataTable>
                    )}
                </Panel>
            </Page>

            {confirmingBulkDelete ? (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setConfirmingBulkDelete(false);
                        }
                    }}
                    title={t((d) => d.common.deleteSelected)}
                    description={t((d) => d.pages.walletTransactions.deleteSelectedHint)}
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={deleteSelected}
                />
            ) : null}

            {merging === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setMerging(null);
                        }
                    }}
                    title={t((d) => d.pages.walletTransactions.duplicates.mergeConfirmTitle).replace(
                        '{count}',
                        String(merging.transactions.length),
                    )}
                    description={t((d) => d.pages.walletTransactions.duplicates.mergeConfirmHint)}
                    confirmLabel={t((d) => d.pages.walletTransactions.duplicates.merge)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await mergeDuplicateTransactions(
                                subjectId,
                                merging.transactions.map((transaction) => transaction.id),
                            );
                            toast.success(t((d) => d.pages.walletTransactions.duplicates.merged));
                            setMerging(null);
                            setReloadKey((current) => current + 1);
                        } catch (cause: unknown) {
                            toast.error(t((d) => d.pages.walletTransactions.duplicates.failed));

                            throw cause;
                        }
                    }}
                />
            )}

            {deleting === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setDeleting(null);
                        }
                    }}
                    title={t((d) => d.pages.walletTransactions.deleteTitle)}
                    description={t((d) => d.pages.walletTransactions.deleteHint)}
                    detail={
                        <p className="note">
                            {deleting.description} ·{' '}
                            <span className="tabular-nums">
                                {formatMinor(deleting.amountMinor, deleting.currencyCode, locale)}
                            </span>
                        </p>
                    }
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        try {
                            await deleteTransaction(subjectId, deleting.id);
                            toast.success(t((d) => d.pages.walletTransactions.deleted));
                            setDeleting(null);
                            setReloadKey((current) => current + 1);
                        } catch (cause: unknown) {
                            toast.error(t((d) => d.common.deleteFailed));

                            throw cause;
                        }
                    }}
                />
            )}

            <PanelDialog
                open={adding || editing !== null}
                onOpenChange={(next) => {
                    if (!next) {
                        // Closed without saving: back to empty, so the next thing opened does not
                        // show what was typed into this one.
                        setAdding(false);
                        setEditing(null);
                        setForm(emptyTransactionForm());
                        errors.reset();
                    }
                }}
                title={editing === null ? t((d) => d.pages.walletTransactions.add) : t((d) => d.common.edit)}
                description={t((d) => d.pages.walletTransactions.addHint)}
                size="lg"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                setAdding(false);
                                setEditing(null);
                                setForm(emptyTransactionForm());
                                errors.reset();
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.common.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={
                                saving ||
                                isSystem ||
                                form.account_id === '' ||
                                form.amount.trim() === '' ||
                                form.booked_at === '' ||
                                form.description.trim() === ''
                            }
                            onClick={() => {
                                void submit();
                            }}
                        >
                            <Check className="size-3.5" aria-hidden="true" />
                            {t((d) => d.common.save)}
                        </PanelButton>
                    </>
                }
            >
                <FieldGrid>
                    {isSystem ? (
                        <p className="note sm:col-span-2">{t((d) => d.pages.walletTransactions.systemNotice)}</p>
                    ) : null}

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.account)}
                        htmlFor="tx-account"
                        error={errors.get('account_id')}
                        {...(editing === null ? {} : { hint: t((d) => d.pages.walletTransactions.accountLocked) })}
                    >
                        <PanelSelect
                            id="tx-account"
                            disabled={editing !== null}
                            value={form.account_id}
                            onValueChange={(value) => {
                                const account = accounts.find((candidate) => candidate.id === value);

                                setForm((current) => ({
                                    ...current,
                                    account_id: value,
                                    // The source's default when it has one. A broker or a crypto
                                    // source holds several currencies and carries none, so the
                                    // choice already made stands.
                                    currency_code: account?.currencyCode ?? current.currency_code,
                                }));
                            }}
                            options={(editing === null ? streamAccounts : accounts).map((account) => ({
                                value: account.id,
                                label:
                                    account.currencyCode === null
                                        ? sourceName(
                                              account,
                                              t((d) => d.pages.wallet.generalSource),
                                          )
                                        : `${sourceName(
                                              account,
                                              t((d) => d.pages.wallet.generalSource),
                                          )} (${account.currencyCode})`,
                            }))}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.currency)}
                        htmlFor="tx-currency"
                        error={errors.get('currency_code')}
                    >
                        <PanelSelect
                            id="tx-currency"
                            disabled={isSystem}
                            value={form.currency_code}
                            onValueChange={(value) => {
                                setForm((current) => ({ ...current, currency_code: value }));
                            }}
                            options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                        />
                    </Field>

                    <Field label={t((d) => d.documentsReview.transactionDirection)}>
                        {/* The sign is the direction, chosen here rather than typed as a minus — and
                            switching it drops a category that cannot move that way. */}
                        <ButtonGroup
                            stretch
                            disabled={isSystem}
                            value={form.direction}
                            onChange={(value) => {
                                setForm((current) => ({
                                    ...current,
                                    direction: value,
                                    category_code: categoryFor(page?.categories ?? [], current.category_code, value),
                                }));
                            }}
                            options={[
                                { value: 'out', label: t((d) => d.documentsReview.directionOut) },
                                { value: 'in', label: t((d) => d.documentsReview.directionIn) },
                            ]}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.amount)}
                        htmlFor="tx-amount"
                        error={errors.get('amount_minor')}
                        hint={t((d) => d.pages.walletTransactions.form.amountHint)}
                    >
                        <PanelInput
                            id="tx-amount"
                            disabled={isSystem}
                            type="number"
                            min={0}
                            step="0.01"
                            value={form.amount}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, amount: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.bookedAt)}
                        htmlFor="tx-booked"
                        error={errors.get('booked_at')}
                    >
                        <PanelDate
                            block
                            disableFuture
                            disabled={isSystem}
                            value={form.booked_at === '' ? null : form.booked_at}
                            onChange={(next) => {
                                setForm((current) => ({ ...current, booked_at: next ?? '' }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.counterparty)}
                        htmlFor="tx-counterparty"
                        error={errors.get('counterparty')}
                        hint={t((d) => d.pages.walletTransactions.form.counterpartyHint)}
                    >
                        <PanelInput
                            id="tx-counterparty"
                            disabled={isSystem}
                            icon={<UserIcon className="size-3.5" />}
                            value={form.counterparty}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, counterparty: event.target.value }));
                            }}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.form.description)}
                        htmlFor="tx-description"
                        error={errors.get('description')}
                    >
                        <PanelInput
                            id="tx-description"
                            disabled={isSystem}
                            icon={<AlignLeftIcon className="size-3.5" />}
                            value={form.description}
                            onChange={(event) => {
                                setForm((current) => ({ ...current, description: event.target.value }));
                            }}
                        />
                    </Field>

                    {/* Answered here rather than in a second dialog afterwards: someone typing a
                        transaction already knows what it was, and asking again later is work for
                        nothing. Optional — a row can be recorded now and answered from the
                        worklist, which is what imported and generated rows do. */}
                    <Field
                        label={t((d) => d.pages.walletTransactions.classify.category)}
                        error={errors.get('category_code')}
                    >
                        <PanelSelect
                            searchable
                            disabled={isSystem}
                            value={form.category_code}
                            placeholder={t((d) => d.pages.walletTransactions.classify.later)}
                            emptyLabel={t((d) => d.pages.walletTransactions.classify.later)}
                            onValueChange={(next) => {
                                setForm((current) => ({ ...current, category_code: next }));
                            }}
                            options={categoriesFor(page?.categories ?? [], form.direction).map((category) => ({
                                value: category.code,
                                label: t((d) => d.pages.walletTransactions.categories[category.code] ?? category.code),
                            }))}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.bank.label)}
                        htmlFor="tx-bank"
                        hint={t((d) => d.pages.walletTransactions.bank.hint)}
                        error={errors.get('bank_id')}
                    >
                        <PanelSelect
                            searchable
                            id="tx-bank"
                            disabled={isSystem}
                            value={form.bank_id}
                            placeholder={t((d) => d.pages.walletTransactions.bank.none)}
                            emptyLabel={t((d) => d.pages.walletTransactions.bank.none)}
                            onValueChange={(next) => {
                                setForm((current) => ({ ...current, bank_id: next }));
                            }}
                            optionVisual="bare"
                            options={bankOptions(
                                banks,
                                t((d) => d.pages.walletTransactions.bank.closed),
                            )}
                        />
                    </Field>

                    <Field
                        label={t((d) => d.pages.walletTransactions.tags.label)}
                        htmlFor="tx-tags"
                        hint={t((d) => d.pages.walletTransactions.tags.hint)}
                    >
                        <TagsInput
                            id="tx-tags"
                            disabled={isSystem}
                            value={form.tags}
                            suggestions={tags}
                            onChange={(next) => {
                                setForm((current) => ({ ...current, tags: next }));
                            }}
                        />
                    </Field>

                    {/* Only once a category has been chosen: the country qualifies the answer, and
                        asking where nothing came from is a field with no question behind it. */}
                    {form.category_code === '' ? null : (
                        <Field label={t((d) => d.pages.walletTransactions.classify.country)}>
                            <PanelSelect
                                searchable
                                disabled={isSystem}
                                value={form.country_code}
                                onValueChange={(next) => {
                                    setForm((current) => ({ ...current, country_code: next }));
                                }}
                                options={countries.map((country) => ({
                                    value: country.code,
                                    label: country.name,
                                }))}
                            />
                        </Field>
                    )}
                </FieldGrid>
            </PanelDialog>
        </AppShell>
    );
}

/**
 * Lookalike transactions, a group at a time, with the two things a person can do about them.
 *
 * Grouped rather than flagged in the ordinary list: a duplicate is a relation between rows, and the
 * decision is about the set — whether they are one payment at all, and which row stays — so the rows
 * are shown together, with the one a merge keeps marked before anyone presses Merge.
 */
function DuplicateGroups({
    groups,
    onMerge,
    onKeep,
}: {
    groups: DuplicateGroup[];
    onMerge: (group: DuplicateGroup) => void;
    onKeep: (group: DuplicateGroup) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <div className="space-y-4 p-3">
            <p className="note">{t((d) => d.pages.walletTransactions.duplicates.hint)}</p>

            {groups.map((group) => (
                <section key={group.survivorId} className="rounded-md border border-border">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-3 py-2">
                        <span className="text-sm font-medium">
                            {t((d) => d.pages.walletTransactions.duplicates.groupTitle).replace(
                                '{count}',
                                String(group.transactions.length),
                            )}
                        </span>
                        <div className="flex gap-1.5">
                            <PanelButton
                                onClick={() => {
                                    onKeep(group);
                                }}
                            >
                                {t((d) => d.pages.walletTransactions.duplicates.keep)}
                            </PanelButton>
                            <PanelButton
                                primary
                                onClick={() => {
                                    onMerge(group);
                                }}
                            >
                                {t((d) => d.pages.walletTransactions.duplicates.merge)}
                            </PanelButton>
                        </div>
                    </div>

                    <DataTable
                        stacked
                        head={
                            <>
                                <th>{t((d) => d.pages.walletTransactions.columns.date)}</th>
                                <th>{t((d) => d.pages.walletTransactions.columns.account)}</th>
                                <th>{t((d) => d.pages.walletTransactions.columns.description)}</th>
                                <th>{t((d) => d.pages.walletTransactions.columns.counterparty)}</th>
                                <th>{t((d) => d.pages.walletTransactions.columns.amount)}</th>
                                <th />
                            </>
                        }
                    >
                        {group.transactions.map((transaction) => (
                            <tr key={transaction.id}>
                                <td className="col-primary whitespace-nowrap tabular-nums">
                                    {formatDate(transaction.bookedAt, locale)}
                                </td>
                                <td className="col-hide">
                                    {sourceName(
                                        { kind: transaction.accountKind, displayName: transaction.accountName },
                                        t((d) => d.pages.wallet.generalSource),
                                    )}
                                </td>
                                <td className="col-wide">{transaction.description}</td>
                                {/* Split out for the same reason as the ledger above, and it matters
                                    more here: deciding whether two rows are the same payment is
                                    mostly comparing who was paid, which is easier down a column than
                                    on the second line of two cells. */}
                                <td data-label={t((d) => d.pages.walletTransactions.columns.counterparty)}>
                                    {transaction.counterparty === null ? (
                                        <span className="note">—</span>
                                    ) : (
                                        <div className="cell-name" title={transaction.counterparty}>
                                            {transaction.counterparty}
                                        </div>
                                    )}
                                </td>
                                <td
                                    className={`tabular-nums whitespace-nowrap ${
                                        transaction.amountMinor < 0 ? 'is-out' : 'is-in'
                                    }`}
                                >
                                    {formatMinor(transaction.amountMinor, transaction.currencyCode, locale)}
                                </td>
                                <td className="col-end text-right">
                                    {/* Which row stays, said before the button is pressed: the server
                                        decided it by the same rule the merge applies. */}
                                    <div className="flex flex-wrap justify-end gap-1">
                                        {transaction.id === group.survivorId ? (
                                            <Pill tone="accent">
                                                {t((d) => d.pages.walletTransactions.duplicates.survivor)}
                                            </Pill>
                                        ) : null}
                                        {transaction.documentId === null ? null : (
                                            <Pill>{t((d) => d.pages.walletTransactions.duplicates.hasDocument)}</Pill>
                                        )}
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </DataTable>
                </section>
            ))}
        </div>
    );
}

function TableSkeleton(): JSX.Element {
    return (
        <div className="space-y-2 p-3">
            {[0, 1, 2, 3].map((index) => (
                <Skeleton key={index} className="tile h-9 w-full" />
            ))}
        </div>
    );
}
