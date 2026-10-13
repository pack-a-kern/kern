import { Check, Merge, Pencil, Tag as TagIcon, Trash2, X } from 'lucide-react';
import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteTag, mergeTags, renameTag } from '../../api/walletApi';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import type { Tag } from '../../types/wallet';
import BulkActions from '../BulkActions';
import Checkbox from '../Checkbox';
import ConfirmDialog from '../ConfirmDialog';
import { ActionGroup, Field, PanelButton, PanelDialog, PanelInput, PanelSelect, Row, RowList } from '../panel';
import { DropdownMenuItem } from '../ui/dropdown-menu';
import { useRowSelection } from '../../hooks/useRowSelection';

/**
 * Tidying a vocabulary that grew by itself.
 *
 * Tags arrive from two directions — typed on a transaction, and proposed by a reading of a statement
 * — so a taxpayer ends up with "restaurants" and "eating out" meaning one thing, and one tag with a
 * typo in it. The three repairs are here, next to each other, because they are the same job:
 *
 * - **Rename** when the name is wrong. Refused when the new name already exists, because that is a
 *   merge and a merge deletes a tag.
 * - **Merge** when two tags mean one thing. Every transaction and every remembered rule moves.
 * - **Delete** when a tag is not wanted. Only the label goes — figures, categories and documents stay.
 */
export default function TagsDialog({
    subjectId,
    tags,
    open,
    onOpenChange,
    onChanged,
}: {
    subjectId: string;
    tags: Tag[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Reload the list: renaming or merging changes what every transaction shows. */
    onChanged: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<{ id: string; name: string } | null>(null);
    /*
     * Merging is an act on a selection, not on a row.
     *
     * Folding four spellings into one was four trips through a per-row select, each merging a pair —
     * and the thing people actually have is four spellings of one word. Ticking them and naming the
     * survivor is one decision, which is also how the counterparties list already merges.
     */
    const [merging, setMerging] = useState(false);
    const [mergeTarget, setMergeTarget] = useState('');
    const [deleting, setDeleting] = useState<Tag | null>(null);
    const [busy, setBusy] = useState(false);
    const selection = useRowSelection(tags.map((tag) => tag.id));
    const picked = tags.filter((tag) => selection.has(tag.id));

    const run = async (work: () => Promise<void>, success: string): Promise<void> => {
        setBusy(true);

        try {
            await work();
            toast.success(success);
            onChanged();
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.walletTransactions.tags.failed),
                {
                    description: describeApiFailure(cause, t),
                },
            );
        } finally {
            setBusy(false);
            setEditing(null);
            setMerging(false);
            setMergeTarget('');
            selection.clear();
        }
    };

    return (
        <>
            <PanelDialog
                open={open}
                onOpenChange={(next) => {
                    if (!next) {
                        setEditing(null);
                        setMerging(false);
                        selection.clear();
                    }

                    onOpenChange(next);
                }}
                title={t((d) => d.pages.walletTransactions.tags.manage)}
                description={t((d) => d.pages.walletTransactions.tags.manageHint)}
                size="lg"
                actions={
                    <PanelButton
                        onClick={() => {
                            onOpenChange(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.common.close)}
                    </PanelButton>
                }
            >
                {tags.length === 0 ? (
                    <p className="note">{t((d) => d.pages.walletTransactions.tags.empty)}</p>
                ) : (
                    <>
                        {/* A header of its own: tick everything at one end, what to do with what
                            is ticked at the other. Above the rows rather than in the dialog's foot,
                            where Close lives — closing the dialog and acting on what is in it are
                            not neighbours.

                            The actions stay on screen while nothing is ticked, disabled, because
                            the row they share with the tick-all box exists either way and a control
                            that comes and goes beside a fixed one makes the row jump. */}
                        <div className="border-border/70 mb-2 flex items-center justify-between gap-3 border-b pb-2">
                            <label className="note flex cursor-pointer items-center gap-2.5">
                                <Checkbox
                                    checked={selection.allOnPage}
                                    indeterminate={selection.someOnPage}
                                    onCheckedChange={(next) => {
                                        selection.toggleAll(next);
                                    }}
                                    aria-label={t((d) => d.common.selectPage)}
                                />
                                {t((d) => d.common.selectPage)}
                            </label>
                            <div className="flex justify-end">
                                <BulkActions alwaysVisible count={picked.length} disabled={busy}>
                                    <DropdownMenuItem
                                        /* Two at least, or there is nothing to fold into anything.
                                       Shown and refused rather than hidden: a menu whose contents
                                       change with the count is one a reader discovers twice. */
                                        disabled={picked.length < 2}
                                        onSelect={() => {
                                            setEditing(null);
                                            // The first ticked name, offered rather than imposed.
                                            setMergeTarget(picked[0]?.id ?? '');
                                            setMerging(true);
                                        }}
                                    >
                                        <Merge className="size-4" aria-hidden="true" />
                                        {t((d) => d.pages.walletTransactions.tags.mergeConfirm)}
                                    </DropdownMenuItem>
                                </BulkActions>
                            </div>
                        </div>
                        <RowList>
                            {tags.map((tag) => (
                                <Row
                                    key={tag.id}
                                    /*
                                    Edited in place: the field replaces the name where the name was,
                                    rather than appearing beside it. A tag and a box asking for a new
                                    one are the same thing said twice, and on a phone they are two
                                    lines of it.

                                    Save is fused to the field, as the settings screen's own
                                    replace-a-secret control is, so the row stays one object. `Enter`
                                    commits — a one-field form is a sentence, and reaching for a
                                    button to end it is a step the keyboard already offers — and
                                    `Escape` leaves, because a rename begun by accident otherwise has
                                    no way out but closing the dialog.
                                */
                                    title={
                                        editing?.id === tag.id ? (
                                            <PanelInput
                                                autoFocus
                                                /* What is being typed, said at the leading edge: the
                                               row's own name is gone while this is open, so without
                                               it the line is a bare box where a tag was. */
                                                icon={<TagIcon className="size-3.5" aria-hidden="true" />}
                                                value={editing.name}
                                                aria-label={t((d) => d.pages.walletTransactions.tags.rename)}
                                                onChange={(event) => {
                                                    setEditing({ id: tag.id, name: event.target.value });
                                                }}
                                                onKeyDown={(event) => {
                                                    if (event.key === 'Escape') {
                                                        setEditing(null);

                                                        return;
                                                    }

                                                    if (event.key === 'Enter' && editing.name.trim() !== '' && !busy) {
                                                        event.preventDefault();
                                                        void run(
                                                            () => renameTag(subjectId, tag.id, editing.name),
                                                            t((d) => d.pages.walletTransactions.tags.renamed),
                                                        );
                                                    }
                                                }}
                                                actionEnd={
                                                    <PanelButton
                                                        primary
                                                        disabled={busy || editing.name.trim() === ''}
                                                        title={t((d) => d.common.save)}
                                                        onClick={() => {
                                                            void run(
                                                                () => renameTag(subjectId, tag.id, editing.name),
                                                                t((d) => d.pages.walletTransactions.tags.renamed),
                                                            );
                                                        }}
                                                    >
                                                        <Check className="size-3.5" aria-hidden="true" />
                                                        <span className="hidden sm:inline">
                                                            {t((d) => d.common.save)}
                                                        </span>
                                                    </PanelButton>
                                                }
                                            />
                                        ) : (
                                            <span className="flex items-center gap-2.5">
                                                <Checkbox
                                                    checked={selection.has(tag.id)}
                                                    onCheckedChange={(next) => {
                                                        selection.toggle(tag.id, next);
                                                    }}
                                                    aria-label={tag.name}
                                                />
                                                {tag.name}
                                            </span>
                                        )
                                    }
                                >
                                    {/* Nothing beside the field while it is open: the row *is* the
                                    field, and a control at its edge would be the second thing this
                                    line is for. */}
                                    {editing?.id === tag.id ? null : (
                                        /*
                                        One control, not three loose buttons — the same joined group
                                        every row in this application ends with, and three separate
                                        ones beside a tag name read as three separate decisions.

                                        The words step aside on a phone, leaving the marks, so the
                                        group stays on the tag's own line instead of wrapping under
                                        it. Each keeps its word as the hover and the accessible name.
                                    */
                                        <ActionGroup
                                            actions={[
                                                {
                                                    key: 'rename',
                                                    icon: <Pencil className="size-3.5" aria-hidden="true" />,
                                                    label: (
                                                        <span className="hidden sm:inline">
                                                            {t((d) => d.pages.walletTransactions.tags.rename)}
                                                        </span>
                                                    ),
                                                    title: t((d) => d.pages.walletTransactions.tags.rename),
                                                    onClick: () => {
                                                        setEditing({ id: tag.id, name: tag.name });
                                                    },
                                                },
                                                {
                                                    key: 'delete',
                                                    label: <Trash2 className="size-3.5" aria-hidden="true" />,
                                                    title: t((d) => d.common.delete),
                                                    tone: 'destructive' as const,
                                                    onClick: () => {
                                                        setDeleting(tag);
                                                    },
                                                },
                                            ]}
                                        />
                                    )}
                                </Row>
                            ))}
                        </RowList>
                    </>
                )}
            </PanelDialog>

            {!merging ? null : (
                <PanelDialog
                    open
                    size="md"
                    title={t((d) => d.pages.walletTransactions.tags.mergeConfirm)}
                    description={t((d) => d.pages.walletTransactions.tags.mergeManyHint).replace(
                        '{count}',
                        String(picked.length),
                    )}
                    onOpenChange={(next) => {
                        if (!next) {
                            setMerging(false);
                        }
                    }}
                    actions={
                        <>
                            <PanelButton
                                onClick={() => {
                                    setMerging(false);
                                }}
                            >
                                <X className="size-3.5" aria-hidden="true" />
                                {t((d) => d.common.cancel)}
                            </PanelButton>
                            <PanelButton
                                primary
                                disabled={busy || mergeTarget === '' || picked.length < 2}
                                onClick={() => {
                                    const survivor = mergeTarget;
                                    const folded = picked.filter((tag) => tag.id !== survivor);

                                    void run(
                                        async () => {
                                            /*
                                             * One call each, because the endpoint folds one tag into one
                                             * other: `POST tags/{tag}/merge` names the tag that goes in
                                             * the path and the one that survives in the body. Sequential
                                             * rather than parallel — they all write the same survivor's
                                             * rules, and a failure halfway leaves the merges that
                                             * already ran done rather than half-done.
                                             */
                                            for (const tag of folded) {
                                                await mergeTags(subjectId, tag.id, survivor);
                                            }
                                        },
                                        t((d) => d.pages.walletTransactions.tags.merged),
                                    );
                                }}
                            >
                                <Merge className="size-3.5" aria-hidden="true" />
                                {t((d) => d.pages.walletTransactions.tags.mergeConfirm)}
                            </PanelButton>
                        </>
                    }
                >
                    {/* Which name survives, chosen from the ticked ones only: merging into a tag
                        nobody selected would be a fifth tag appearing in an act about four. */}
                    <Field label={t((d) => d.pages.walletTransactions.tags.keepName)}>
                        <PanelSelect
                            searchable
                            className="w-full"
                            icon={<TagIcon className="size-3.5" aria-hidden="true" />}
                            value={mergeTarget}
                            onValueChange={setMergeTarget}
                            options={picked.map((tag) => ({ value: tag.id, label: tag.name }))}
                        />
                    </Field>
                </PanelDialog>
            )}

            {deleting === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setDeleting(null);
                        }
                    }}
                    title={t((d) => d.pages.walletTransactions.tags.deleteTitle)}
                    description={t((d) => d.pages.walletTransactions.tags.deleteHint)}
                    detail={<p className="note">{deleting.name}</p>}
                    confirmLabel={t((d) => d.common.delete)}
                    cancelLabel={t((d) => d.common.cancel)}
                    onConfirm={async () => {
                        const tag = deleting;
                        setDeleting(null);

                        await run(
                            () => deleteTag(subjectId, tag.id),
                            t((d) => d.pages.walletTransactions.tags.deleted),
                        );
                    }}
                />
            )}
        </>
    );
}
