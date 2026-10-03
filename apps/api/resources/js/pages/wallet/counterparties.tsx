import { Head } from '@inertiajs/react';
import { Merge, Pencil, Tag as TagIcon, Trash2, UsersRound } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import AppShell from '../../components/AppShell';
import {
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
    Pill,
    SearchInput,
    ToolbarRow,
    ToolbarRows,
} from '../../components/panel';
import Checkbox from '../../components/Checkbox';
import Pagination from '../../components/Pagination';
import { Skeleton } from '../../components/ui/skeleton';
import ConfirmDialog from '../../components/ConfirmDialog';
import TagsDialog from '../../components/wallet/TagsDialog';
import TagsInput from '../../components/wallet/TagsInput';
import { useRememberedPaging } from '../../hooks/useRememberedState';
import { useRowSelection } from '../../hooks/useRowSelection';
import { categoriesFor, parseTaxCategories, type Tag, type TaxCategory } from '../../types/wallet';
import { useTranslation } from '../../i18n';
import { apiRequest } from '../../lib/http';
import { getActiveSubjectId } from '../../lib/subjectStore';

/** One direction's remembered answer, or nothing where nobody has decided yet. */
interface Rule {
    id: string;
    categoryCode: string | null;
    countryCode: string | null;
    source: string | null;
    tagIds: string[];
    /** How many of this direction's transactions already carry an answer. */
    answered: number;
}

interface Party {
    name: string;
    rows: number;
    in: Rule | null;
    out: Rule | null;
}

/** What the edit dialog is working on: a party, and a draft answer for each direction it has. */
interface Draft {
    party: Party;
    in: { categoryCode: string; tags: string[] } | null;
    out: { categoryCode: string; tags: string[] } | null;
}

interface Listing {
    counterparties: Party[];
    total: number;
    categories: TaxCategory[];
    tags: Record<string, string>;
}

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

function str(row: Record<string, unknown>, key: string): string {
    return typeof row[key] === 'string' ? row[key] : '';
}

function parseRule(value: unknown): Rule | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }

    const row = obj(value);

    return {
        id: str(row, 'id'),
        categoryCode: typeof row.category_code === 'string' ? row.category_code : null,
        countryCode: typeof row.country_code === 'string' ? row.country_code : null,
        source: typeof row.source === 'string' ? row.source : null,
        tagIds: (Array.isArray(row.tag_ids) ? row.tag_ids : []).filter((id): id is string => typeof id === 'string'),
        answered: typeof row.answered === 'number' ? row.answered : 0,
    };
}

function parseListing(payload: unknown): Listing {
    const body = obj(payload);
    const tags: Record<string, string> = {};

    for (const [id, name] of Object.entries(obj(body.tags))) {
        if (typeof name === 'string') {
            tags[id] = name;
        }
    }

    return {
        counterparties: (Array.isArray(body.counterparties) ? body.counterparties : []).map((entry): Party => {
            const row = obj(entry);

            return {
                name: str(row, 'name'),
                rows: typeof row.rows === 'number' ? row.rows : 0,
                in: parseRule(row.in),
                out: parseRule(row.out),
            };
        }),
        total: typeof body.total === 'number' ? body.total : 0,
        categories: parseTaxCategories(payload),
        tags,
    };
}

/**
 * The parties this taxpayer has dealt with, and the answers held about them.
 *
 * ## Why a party is one row and not two
 *
 * The directory keeps a row per direction, and the two decisions this screen exists for are both
 * cross-direction: filling the side nobody has answered, and folding several spellings into one. A
 * person who lends money to someone and is paid back does not think of that as two parties, and
 * editing them one direction at a time meant opening the same party twice to finish one thought.
 *
 * ## Why a search is not paged
 *
 * Because merging is the reason to search. Paged, the merge could only reach the spellings that
 * happened to land on the first page, which is the opposite of what searching for them was for.
 * Browsing all two and a half thousand still pages, because that is a list rather than a shortlist.
 */
export default function WalletCounterparties(): JSX.Element {
    const { t, locale } = useTranslation();
    const subjectId = getActiveSubjectId();

    const [listing, setListing] = useState<Listing | null>(null);
    const [failed, setFailed] = useState(false);
    const [query, setQuery] = useState('');
    const [typed, setTyped] = useState('');
    const [reloads, setReloads] = useState(0);
    const { page, pageSize, setPage, setPageSize } = useRememberedPaging('wallet.counterparties');

    const [editing, setEditing] = useState<Draft | null>(null);
    const [merging, setMerging] = useState(false);
    const [forgetting, setForgetting] = useState(false);
    const [managingTags, setManagingTags] = useState(false);
    const [survivor, setSurvivor] = useState('');
    const [saving, setSaving] = useState(false);

    const parties = listing?.counterparties ?? [];
    const selection = useRowSelection(parties.map((party) => party.name));

    // Typed into, then asked for: a search decrypts this taxpayer's whole directory on the server,
    // so it is not a question to ask on every keystroke.
    useEffect(() => {
        const timer = window.setTimeout(() => {
            setQuery(typed);
            setPage(1);
        }, 350);

        return () => {
            window.clearTimeout(timer);
        };
    }, [typed, setPage]);

    useEffect(() => {
        if (subjectId === null) {
            return;
        }

        let active = true;
        const params = new URLSearchParams({ page: String(page), per_page: String(pageSize) });

        if (query !== '') {
            params.set('q', query);
        }

        apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/counterparties?${params.toString()}`).then(
            (text: string) => {
                if (active) {
                    setListing(parseListing(JSON.parse(text)));
                    setFailed(false);
                }
            },
            () => {
                if (active) {
                    setFailed(true);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [subjectId, query, page, pageSize, reloads]);

    if (subjectId === null) {
        return (
            <AppShell>
                <Page title={t((d) => d.pages.walletCounterparties.title)}>
                    <Panel title={t((d) => d.pages.walletCounterparties.title)}>
                        <p className="note">{t((d) => d.pages.wallet.noSubject)}</p>
                    </Panel>
                </Page>
            </AppShell>
        );
    }

    const searching = query !== '';
    const tags = listing?.tags ?? {};
    const tagList: Tag[] = Object.entries(tags).map(([id, name]) => ({ id, name }));
    const picked = parties.filter((party) => selection.has(party.name));

    const categoryLabel = (code: string): string => t((d) => d.pages.walletTransactions.categories[code] ?? code);

    /** The tag names a rule holds, since the dialog edits names and the payload carries ids. */
    const tagNamesOf = (rule: Rule | null): string[] =>
        (rule?.tagIds ?? []).map((id) => tags[id]).filter((name): name is string => name !== undefined);

    const reload = (): void => {
        setReloads((previous) => previous + 1);
    };

    const openEditor = (party: Party): void => {
        setEditing({
            party,
            in: party.in === null ? null : { categoryCode: party.in.categoryCode ?? '', tags: tagNamesOf(party.in) },
            out:
                party.out === null ? null : { categoryCode: party.out.categoryCode ?? '', tags: tagNamesOf(party.out) },
        });
    };

    /** Both directions, each in its own request, because each is its own rule row. */
    const saveRules = (): void => {
        if (editing === null) {
            return;
        }

        const writes = (['in', 'out'] as const)
            .map((direction) => {
                const rule = editing.party[direction];
                const draft = editing[direction];

                return rule === null || draft === null
                    ? null
                    : apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/counterparties/${rule.id}`, {
                          method: 'PATCH',
                          body: {
                              category_code: draft.categoryCode === '' ? null : draft.categoryCode,
                              tags: draft.tags,
                              // This screen states what a party is, so its answer wins over the
                              // rows. The dialog says how many that replaces before this runs.
                              replace: true,
                          },
                      });
            })
            .filter((write): write is Promise<string> => write !== null);

        setSaving(true);

        Promise.all(writes).then(
            (texts) => {
                const applied = texts.reduce((sum, text) => {
                    const count = obj(JSON.parse(text)).transactions_updated;

                    return sum + (typeof count === 'number' ? count : 0);
                }, 0);

                setSaving(false);
                setEditing(null);
                toast.success(t((d) => d.pages.walletCounterparties.ruleSaved).replace('{count}', String(applied)));
                reload();
            },
            () => {
                setSaving(false);
                toast.error(t((d) => d.pages.walletCounterparties.saveFailed));
            },
        );
    };

    const post = (path: string, body: Record<string, unknown>, done: (text: string) => void): Promise<void> => {
        setSaving(true);

        return apiRequest(`/v1/subjects/${encodeURIComponent(subjectId)}/wallet/counterparties/${path}`, {
            method: 'POST',
            body,
        }).then(
            (text: string) => {
                setSaving(false);
                selection.clear();
                done(text);
                reload();
            },
            () => {
                setSaving(false);
                toast.error(t((d) => d.pages.walletCounterparties.saveFailed));
            },
        );
    };

    const idsOf = (chosen: Party[]): string[] =>
        chosen.flatMap((party) => [party.in?.id, party.out?.id]).filter((id): id is string => id !== undefined);

    return (
        <AppShell>
            <Head title={t((d) => d.pages.walletCounterparties.title)} />
            <Page
                title={t((d) => d.pages.walletCounterparties.title)}
                lede={t((d) => d.pages.walletCounterparties.description)}
            >
                <Panel
                    title={t((d) => d.pages.walletCounterparties.title)}
                    icon={<UsersRound className="size-4" aria-hidden="true" />}
                    flush
                    foot={
                        // Gone while searching: those results are not a page of anything, they are
                        // every party that matched.
                        searching ? undefined : (
                            <Pagination
                                page={page}
                                pageSize={pageSize}
                                total={listing?.total ?? 0}
                                unit={t((d) => d.pages.walletCounterparties.unit)}
                                onPageChange={setPage}
                                onPageSizeChange={(size: number) => {
                                    setPageSize(size);
                                    setPage(1);
                                }}
                            />
                        )
                    }
                >
                    {/* One line: what to look for, and what to do with what is ticked. */}
                    <ToolbarRows>
                        <ToolbarRow
                            end={
                                <PanelButton
                                    title={t((d) => d.pages.walletCounterparties.manageTags)}
                                    onClick={() => {
                                        setManagingTags(true);
                                    }}
                                >
                                    <TagIcon className="size-3.5" aria-hidden="true" />
                                    <span className="sr-only">{t((d) => d.pages.walletCounterparties.manageTags)}</span>
                                </PanelButton>
                            }
                        >
                            <SearchInput
                                value={typed}
                                onChange={setTyped}
                                placeholder={t((d) => d.pages.walletCounterparties.search)}
                            />
                            <PanelButton
                                disabled={picked.length < 2}
                                onClick={() => {
                                    // The busiest spelling, offered rather than imposed — a person
                                    // who wants a shorter name types one.
                                    setSurvivor(picked[0]?.name ?? '');
                                    setMerging(true);
                                }}
                            >
                                <Merge className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletCounterparties.merge)}
                                {picked.length < 2 ? null : <Pill tone="accent">{picked.length}</Pill>}
                            </PanelButton>
                            <PanelButton
                                disabled={picked.length === 0}
                                onClick={() => {
                                    setForgetting(true);
                                }}
                            >
                                <Trash2 className="size-3.5 text-destructive" aria-hidden="true" />
                                {t((d) => d.pages.walletCounterparties.forget)}
                                {picked.length === 0 ? null : <Pill tone="accent">{picked.length}</Pill>}
                            </PanelButton>
                        </ToolbarRow>
                    </ToolbarRows>

                    {failed ? <p className="note p-4">{t((d) => d.pages.walletCounterparties.loadFailed)}</p> : null}

                    {listing === null && !failed ? (
                        <div className="space-y-2 p-4">
                            {[0, 1, 2].map((index) => (
                                <Skeleton key={index} className="h-10 w-full" />
                            ))}
                        </div>
                    ) : null}

                    {listing === null ? null : parties.length === 0 ? (
                        <EmptyState
                            icon={<UsersRound className="size-5" aria-hidden="true" />}
                            title={t((d) => d.pages.walletCounterparties.empty)}
                            description={t((d) => d.pages.walletCounterparties.emptyHint)}
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
                                    <th>{t((d) => d.pages.walletCounterparties.party)}</th>
                                    <th className="col-figure">{t((d) => d.pages.walletCounterparties.rows)}</th>
                                    <th>{t((d) => d.pages.walletCounterparties.moneyIn)}</th>
                                    <th>{t((d) => d.pages.walletCounterparties.moneyOut)}</th>
                                    <th className="col-end" />
                                </>
                            }
                        >
                            {parties.map((party) => (
                                <tr key={party.name}>
                                    <td className="col-hide w-8">
                                        <Checkbox
                                            checked={selection.has(party.name)}
                                            onCheckedChange={(next) => {
                                                selection.toggle(party.name, next);
                                            }}
                                            aria-label={party.name}
                                        />
                                    </td>
                                    <td className="col-primary col-wide">{party.name}</td>
                                    <td className="col-figure">{party.rows.toLocaleString(locale)}</td>
                                    <td data-label={t((d) => d.pages.walletCounterparties.moneyIn)}>
                                        <RuleCell rule={party.in} label={categoryLabel} />
                                    </td>
                                    <td data-label={t((d) => d.pages.walletCounterparties.moneyOut)}>
                                        <RuleCell rule={party.out} label={categoryLabel} />
                                    </td>
                                    <td className="col-end text-right">
                                        <div className="flex justify-end">
                                            <ActionGroup
                                                actions={[
                                                    {
                                                        key: 'edit',
                                                        label: <Pencil className="size-3.5" aria-hidden="true" />,
                                                        title: t((d) => d.common.edit),
                                                        onClick: () => {
                                                            openEditor(party);
                                                        },
                                                    },
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

            {editing === null ? null : (
                <PanelDialog
                    open
                    size="lg"
                    title={editing.party.name}
                    description={t((d) => d.pages.walletCounterparties.editHint)}
                    onOpenChange={() => {
                        setEditing(null);
                    }}
                    actions={
                        <>
                            <PanelButton
                                onClick={() => {
                                    setEditing(null);
                                }}
                            >
                                {t((d) => d.common.cancel)}
                            </PanelButton>
                            <PanelButton primary disabled={saving} onClick={saveRules}>
                                <Pencil className="size-3.5" aria-hidden="true" />
                                {t((d) => d.common.save)}
                            </PanelButton>
                        </>
                    }
                >
                    {/* Both directions in one dialog: they are one decision about one party, and
                        answering them separately meant opening it twice. A direction the party has
                        never moved money in is absent rather than empty — there is no rule to edit. */}
                    {(['in', 'out'] as const).map((direction) => {
                        const rule = editing.party[direction];
                        const draft = editing[direction];

                        if (rule === null || draft === null) {
                            return null;
                        }

                        return (
                            <section
                                key={direction}
                                className="border-border mt-4 border-t pt-4 first:mt-0 first:border-0 first:pt-0"
                            >
                                <p className="eyebrow mb-2">
                                    {t((d) =>
                                        direction === 'in'
                                            ? d.pages.walletCounterparties.moneyIn
                                            : d.pages.walletCounterparties.moneyOut,
                                    )}
                                    {rule.answered === 0 ? null : (
                                        <span className="note">
                                            {' · '}
                                            {t((d) => d.pages.walletCounterparties.willReplace).replace(
                                                '{count}',
                                                String(rule.answered),
                                            )}
                                        </span>
                                    )}
                                </p>
                                <FieldGrid>
                                    <Field label={t((d) => d.pages.walletCounterparties.category)}>
                                        <PanelSelect
                                            value={draft.categoryCode}
                                            emptyLabel={t((d) => d.pages.walletCounterparties.undecided)}
                                            onValueChange={(next) => {
                                                setEditing({
                                                    ...editing,
                                                    [direction]: { ...draft, categoryCode: next },
                                                });
                                            }}
                                            options={categoriesFor(
                                                listing?.categories ?? [],
                                                direction === 'in' ? 'in' : 'out',
                                            ).map((category) => ({
                                                value: category.code,
                                                label: categoryLabel(category.code),
                                            }))}
                                        />
                                    </Field>
                                    <Field label={t((d) => d.pages.walletCounterparties.tags)}>
                                        <TagsInput
                                            value={draft.tags}
                                            onChange={(next) => {
                                                setEditing({ ...editing, [direction]: { ...draft, tags: next } });
                                            }}
                                            suggestions={tagList}
                                        />
                                    </Field>
                                </FieldGrid>
                            </section>
                        );
                    })}
                </PanelDialog>
            )}

            {!merging ? null : (
                <PanelDialog
                    open
                    title={t((d) => d.pages.walletCounterparties.mergeTitle)}
                    description={t((d) => d.pages.walletCounterparties.mergeHint).replace(
                        '{count}',
                        String(picked.length),
                    )}
                    onOpenChange={() => {
                        setMerging(false);
                    }}
                    actions={
                        <>
                            <PanelButton
                                onClick={() => {
                                    setMerging(false);
                                }}
                            >
                                {t((d) => d.common.cancel)}
                            </PanelButton>
                            <PanelButton
                                primary
                                disabled={saving || survivor.trim() === ''}
                                onClick={() => {
                                    void post(
                                        'merge',
                                        { counterparty_ids: idsOf(picked), name: survivor.trim() },
                                        (text) => {
                                            const count = obj(JSON.parse(text)).transactions_updated;

                                            setMerging(false);
                                            setSurvivor('');
                                            toast.success(
                                                t((d) => d.pages.walletCounterparties.merged).replace(
                                                    '{count}',
                                                    String(typeof count === 'number' ? count : 0),
                                                ),
                                            );
                                        },
                                    );
                                }}
                            >
                                <Merge className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletCounterparties.merge)}
                            </PanelButton>
                        </>
                    }
                >
                    <FieldGrid>
                        <Field
                            label={t((d) => d.pages.walletCounterparties.survivingName)}
                            help={t((d) => d.pages.walletCounterparties.survivingHelp)}
                        >
                            <PanelInput
                                value={survivor}
                                onChange={(event) => {
                                    setSurvivor(event.target.value);
                                }}
                                maxLength={255}
                            />
                        </Field>
                    </FieldGrid>
                    <ul className="divide-border divide-y">
                        {picked.map((party) => (
                            <li key={party.name} className="flex items-baseline justify-between gap-3 py-2">
                                <span className="truncate text-xs font-semibold">{party.name}</span>
                                <span className="note">{party.rows.toLocaleString(locale)}</span>
                            </li>
                        ))}
                    </ul>
                </PanelDialog>
            )}

            {!forgetting ? null : (
                <ConfirmDialog
                    open
                    title={t((d) => d.pages.walletCounterparties.forgetTitle)}
                    description={t((d) => d.pages.walletCounterparties.forgetHint).replace(
                        '{count}',
                        String(picked.length),
                    )}
                    confirmLabel={t((d) => d.pages.walletCounterparties.forget)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onOpenChange={setForgetting}
                    onConfirm={() =>
                        post('forget', { counterparty_ids: idsOf(picked) }, (text) => {
                            const count = obj(JSON.parse(text)).counterparties_forgotten;

                            setForgetting(false);
                            toast.success(
                                t((d) => d.pages.walletCounterparties.forgotten).replace(
                                    '{count}',
                                    String(typeof count === 'number' ? count : 0),
                                ),
                            );
                        })
                    }
                />
            )}

            <TagsDialog
                subjectId={subjectId}
                tags={tagList}
                open={managingTags}
                onOpenChange={setManagingTags}
                onChanged={reload}
            />
        </AppShell>
    );
}

/** One direction's answer: the category, or that nobody has decided it. */
function RuleCell({ rule, label }: { rule: Rule | null; label: (code: string) => string }): JSX.Element {
    const { t } = useTranslation();

    if (rule === null) {
        return <span className="note">—</span>;
    }

    if (rule.categoryCode === null) {
        return <span className="note">{t((d) => d.pages.walletCounterparties.undecided)}</span>;
    }

    return <Pill tone="accent">{label(rule.categoryCode)}</Pill>;
}
