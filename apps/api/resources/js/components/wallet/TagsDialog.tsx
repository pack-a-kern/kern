import { Trash2, X } from 'lucide-react';
import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { deleteTag, mergeTags, renameTag } from '../../api/walletApi';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import type { Tag } from '../../types/wallet';
import ConfirmDialog from '../ConfirmDialog';
import { PanelButton, PanelDialog, PanelInput, PanelSelect, Row, RowList } from '../panel';

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
    const [mergingId, setMergingId] = useState<string | null>(null);
    const [mergeTarget, setMergeTarget] = useState('');
    const [deleting, setDeleting] = useState<Tag | null>(null);
    const [busy, setBusy] = useState(false);

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
            setMergingId(null);
            setMergeTarget('');
        }
    };

    return (
        <>
            <PanelDialog
                open={open}
                onOpenChange={(next) => {
                    if (!next) {
                        setEditing(null);
                        setMergingId(null);
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
                    <RowList>
                        {tags.map((tag) => (
                            <Row key={tag.id} title={tag.name}>
                                <div className="flex flex-wrap items-center justify-end gap-1.5">
                                    {editing?.id === tag.id ? (
                                        <>
                                            <PanelInput
                                                value={editing.name}
                                                aria-label={t((d) => d.pages.walletTransactions.tags.rename)}
                                                onChange={(event) => {
                                                    setEditing({ id: tag.id, name: event.target.value });
                                                }}
                                            />
                                            <PanelButton
                                                primary
                                                disabled={busy || editing.name.trim() === ''}
                                                onClick={() => {
                                                    void run(
                                                        () => renameTag(subjectId, tag.id, editing.name),
                                                        t((d) => d.pages.walletTransactions.tags.renamed),
                                                    );
                                                }}
                                            >
                                                {t((d) => d.common.save)}
                                            </PanelButton>
                                        </>
                                    ) : mergingId === tag.id ? (
                                        <>
                                            <PanelSelect
                                                searchable
                                                value={mergeTarget}
                                                placeholder={t((d) => d.pages.walletTransactions.tags.merge)}
                                                onValueChange={setMergeTarget}
                                                options={tags
                                                    .filter((candidate) => candidate.id !== tag.id)
                                                    .map((candidate) => ({
                                                        value: candidate.id,
                                                        label: candidate.name,
                                                    }))}
                                            />
                                            <PanelButton
                                                primary
                                                disabled={busy || mergeTarget === ''}
                                                onClick={() => {
                                                    void run(
                                                        () => mergeTags(subjectId, tag.id, mergeTarget),
                                                        t((d) => d.pages.walletTransactions.tags.merged),
                                                    );
                                                }}
                                            >
                                                {t((d) => d.pages.walletTransactions.tags.mergeConfirm)}
                                            </PanelButton>
                                        </>
                                    ) : (
                                        <>
                                            <PanelButton
                                                onClick={() => {
                                                    setMergingId(null);
                                                    setEditing({ id: tag.id, name: tag.name });
                                                }}
                                            >
                                                {t((d) => d.pages.walletTransactions.tags.rename)}
                                            </PanelButton>
                                            <PanelButton
                                                disabled={tags.length < 2}
                                                onClick={() => {
                                                    setEditing(null);
                                                    setMergeTarget('');
                                                    setMergingId(tag.id);
                                                }}
                                            >
                                                {t((d) => d.pages.walletTransactions.tags.merge)}
                                            </PanelButton>
                                            <PanelButton
                                                variant="ghost"
                                                aria-label={t((d) => d.common.delete)}
                                                onClick={() => {
                                                    setDeleting(tag);
                                                }}
                                            >
                                                <Trash2 className="size-3.5" />
                                            </PanelButton>
                                        </>
                                    )}
                                </div>
                            </Row>
                        ))}
                    </RowList>
                )}
            </PanelDialog>

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
