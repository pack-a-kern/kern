import { Camera, FileText, FolderOpen, Upload, Wand2, X } from 'lucide-react';
import { useEffect, useRef, useState, type ChangeEvent, type JSX } from 'react';
import Checkbox from '../Checkbox';
import ConfirmDialog from '../ConfirmDialog';
import { enhanceImage } from '../../api/documentsApi';
import { ApiError } from '../../lib/http';
import { generateLocalId } from '../../lib/db';
import { useAcceptedUploadTypes, useProviderEnhances } from '../../lib/sharedProps';
import type { CaptureItem } from '../../lib/db';

import ErrorText from '../ErrorText';
import { PanelButton, PanelDialog, Status, type StatusTone } from '../panel';
import { useOnlineStatus } from '../../hooks/useOnlineStatus';
import { useCanTakePhoto } from '../../hooks/useMediaQuery';
import { useUploadQueue } from '../../hooks/useUploadQueue';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import type { CaptureStatus } from '../../lib/db';
import { takeSelectedFiles } from '../../lib/fileInput';
import { formatBytes } from '../../lib/format';

const QUEUE_STATUS_KEYS: Record<CaptureStatus, keyof Dictionary['documentsCapture']> = {
    queued: 'statusQueued',
    uploading: 'statusUploading',
    uploaded: 'statusUploaded',
    failed: 'statusFailed',
    rejected: 'statusRejected',
    duplicate: 'statusDuplicate',
};

/**
 * Where one queued upload stands.
 *
 * `failed` is `warn`, not `bad`: the queue retries on its own, so it is a delay rather than a loss.
 * `rejected` is `bad` — the server refused the file and no amount of retrying will change that.
 * `duplicate` is `warn`: also final, but it means the document arrived — the first time.
 */
const QUEUE_STATUS_TONES: Record<CaptureStatus, StatusTone> = {
    queued: 'idle',
    uploading: 'idle',
    uploaded: 'ok',
    failed: 'warn',
    rejected: 'bad',
    // Not `bad`: nothing is wrong with the file, the subject simply already has it.
    duplicate: 'warn',
};

/**
 * Adding a document, as a dialog over the list rather than a page of its own.
 *
 * It used to be `/documents/capture`, a destination you navigated to and then navigated back from
 * — for an action taken *about* the list you were already looking at. The upload queue lives here
 * with it, because a queue you cannot see while it drains is a queue you cannot trust.
 *
 * The dialog stays open while uploads run: closing it does not cancel anything (the queue is in
 * IndexedDB and drains on its own), but watching a document leave the queue is how a person knows
 * it arrived.
 */
/**
 * What a queued document is called in the list.
 *
 * The first file's name, because a document photographed page by page has no name of its own until
 * the server gives it one — and "page 1 of it" is the only honest label available on this device.
 */
function queuedName(item: CaptureItem): string {
    return item.files[0]?.fileName ?? '';
}

/** A file picked but not yet sent, and what has been decided about it. */
interface StagedFile {
    id: string;
    file: File;
    /** Ticked to make this one of the pages of a single document. */
    grouped: boolean;
    enhanced?: boolean;
    /**
     * An object URL for a picked image, so the row can show what it is.
     *
     * Held per file and replaced when the file is — cleaning a photograph produces different bytes,
     * and the point of showing it is to see whether the cleaning helped. Revoked whenever it is
     * replaced or the file leaves, because an object URL lives until it is released.
     */
    preview?: string;
}

/**
 * A filename whose extension matches the bytes it now holds.
 *
 * The server decides a file's type from its content and refuses one whose extension disagrees, so a
 * name is not cosmetic here — it is part of what makes the upload valid.
 */
function renamedFor(name: string, mimeType: string): string {
    const extension = { 'image/png': 'png', 'image/jpeg': 'jpg' }[mimeType];

    if (extension === undefined) {
        return name;
    }

    const stem = name.includes('.') ? name.slice(0, name.lastIndexOf('.')) : name;

    return `${stem}.${extension}`;
}

/** What the whole document weighs — every page, not the first one. */
function queuedSize(item: CaptureItem): number {
    return item.files.reduce((total, file) => total + file.sizeBytes, 0);
}

export default function AddDocumentDialog({
    subjectId,
    open,
    onOpenChange,
    onUploaded,
}: {
    subjectId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Fired once per document that reaches the server, so the list behind this dialog can refresh. */
    onUploaded?: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const [pickError, setPickError] = useState<string | null>(null);
    /**
     * Files picked but not yet sent.
     *
     * Nothing uploads on selection any more. A pick used to start immediately, which meant the one
     * decision that cannot be made afterwards — which files are pages of the same document — had to
     * be made before the files were even visible. Now they are listed, grouped, and sent on a
     * button press.
     */
    const [staged, setStaged] = useState<StagedFile[]>([]);
    const [enhancing, setEnhancing] = useState<string | null>(null);
    const [confirmingClose, setConfirmingClose] = useState(false);
    const accepted = useAcceptedUploadTypes();
    const canEnhance = useProviderEnhances();
    const stagedRef = useRef<StagedFile[]>([]);

    // Kept in an effect, not assigned during render: a ref written while rendering is read back by
    // nothing reliable, and the only reader here runs when this unmounts.
    useEffect(() => {
        stagedRef.current = staged;
    }, [staged]);

    // An object URL lives until it is released, so whatever is still staged when this closes is
    // freed rather than left holding a picked photograph in memory for the life of the tab.
    useEffect(
        () => () => {
            for (const entry of stagedRef.current) {
                if (entry.preview !== undefined) {
                    URL.revokeObjectURL(entry.preview);
                }
            }
        },
        [],
    );
    const groupedCount = staged.filter((entry) => entry.grouped).length;
    const online = useOnlineStatus();
    const { items, enqueue, retry, remove, processing, progress } = useUploadQueue(subjectId, onUploaded);
    const canTakePhoto = useCanTakePhoto();

    /**
     * How many files this pick contained, so "3 of 7" can be counted against something.
     *
     * It cannot be derived from the queue: a successful upload is *deleted* from IndexedDB, so the
     * list only ever shows what is still outstanding. Accumulated rather than replaced, because a
     * second pick while the first is still draining is one batch from the person's point of view.
     */
    const [batchTotal, setBatchTotal] = useState(0);

    const outstanding = items.filter(
        (item) => item.status === 'queued' || item.status === 'uploading' || item.status === 'failed',
    ).length;
    const batchDone = Math.max(0, batchTotal - outstanding);

    const cameraInputRef = useRef<HTMLInputElement | null>(null);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    function handleFiles(event: ChangeEvent<HTMLInputElement>): void {
        setPickError(null);

        // Copied out of the live `FileList` before the input is reset — see `takeSelectedFiles`.
        // Reading it afterwards yields an empty list and the picker appears to do nothing at all.
        const selected = takeSelectedFiles(event.target);

        if (selected.length === 0) {
            return;
        }

        setStaged((current) => [
            ...current,
            ...selected.map((file) => ({
                id: generateLocalId(),
                file,
                grouped: false,
                ...(file.type.startsWith('image/') ? { preview: URL.createObjectURL(file) } : {}),
            })),
        ]);
    }

    /**
     * Send what is staged: every ticked file as one document, each unticked file as its own.
     *
     * The grouping is a tick rather than a mode, because one pick is often both — five separate
     * receipts and one invoice photographed across three pages.
     */
    async function upload(): Promise<void> {
        setPickError(null);

        const grouped = staged.filter((entry) => entry.grouped).map((entry) => entry.file);
        const alone = staged.filter((entry) => !entry.grouped).map((entry) => [entry.file]);
        const documents = grouped.length > 0 ? [grouped, ...alone] : alone;

        if (documents.length === 0) {
            return;
        }

        setBatchTotal((current) => (outstanding === 0 ? documents.length : current + documents.length));

        for (const entry of staged) {
            if (entry.preview !== undefined) {
                URL.revokeObjectURL(entry.preview);
            }
        }

        setStaged([]);

        try {
            for (const files of documents) {
                await enqueue(files);
            }
        } catch {
            // The page's own translated copy, never `error.message`: everything `enqueue` throws is
            // a local failure carrying a developer string, and rendering that would put untranslated
            // text on screen. Server-side rejections travel a different path — they are stored on
            // the queued item and shown against it with their own reason.
            setPickError(t((d) => d.documentsCapture.pickError));
        }
    }

    /**
     * Replace a staged photograph with a cleaned-up version of itself.
     *
     * Before the upload rather than after, so what is stored is the file a person looked at and
     * accepted — one file, not an original and a correction. One at a time because the provider
     * serves one at a time, and a queue of five would simply look frozen.
     */
    async function enhance(entry: StagedFile): Promise<void> {
        setPickError(null);
        setEnhancing(entry.id);

        try {
            const improved = await enhanceImage(subjectId, entry.file);
            // Renamed to match what came back. Cleaning a JPEG can return a PNG, and the upload
            // refuses a file whose extension disagrees with its own bytes — so keeping the original
            // name turns a successful enhancement into a rejected upload.
            const replacement = new File([improved], renamedFor(entry.file.name, improved.type), {
                type: improved.type,
            });

            setStaged((current) =>
                current.map((candidate) => {
                    if (candidate.id !== entry.id) {
                        return candidate;
                    }

                    if (candidate.preview !== undefined) {
                        URL.revokeObjectURL(candidate.preview);
                    }

                    return {
                        ...candidate,
                        file: replacement,
                        enhanced: true,
                        preview: URL.createObjectURL(replacement),
                    };
                }),
            );
        } catch (cause) {
            setPickError(cause instanceof ApiError ? cause.message : t((d) => d.documentsCapture.enhanceFailed));
        } finally {
            setEnhancing(null);
        }
    }

    return (
        <>
            <PanelDialog
                open={open}
                // While files are moving, a stray click outside must not abandon them: the dialog is the
                // only place the queue's progress is visible, and closing it silently is how an upload
                // appears to have finished when it has not.
                dismissable={outstanding === 0}
                onOpenChange={(next) => {
                    if (next || outstanding === 0) {
                        onOpenChange(next);

                        return;
                    }

                    setConfirmingClose(true);
                }}
                title={t((d) => d.documentsCapture.addTitle)}
                description={t((d) => d.documentsCapture.intro)}
                size="lg"
                actions={
                    <>
                        <PanelButton
                            onClick={() => {
                                if (outstanding === 0) {
                                    onOpenChange(false);

                                    return;
                                }

                                setConfirmingClose(true);
                            }}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.common.close)}
                        </PanelButton>

                        {/* Only where `capture` means anything. A desktop browser ignores it and opens
                        the ordinary picker, making this a second "Choose files" with a camera icon. */}
                        {canTakePhoto && (
                            <PanelButton
                                onClick={() => {
                                    cameraInputRef.current?.click();
                                }}
                            >
                                <Camera className="size-3.5" aria-hidden="true" />
                                {t((d) => d.documentsCapture.takePhoto)}
                            </PanelButton>
                        )}

                        <PanelButton
                            primary={staged.length === 0}
                            onClick={() => {
                                fileInputRef.current?.click();
                            }}
                        >
                            <FolderOpen className="size-3.5" aria-hidden="true" />
                            {t((d) => d.documentsCapture.chooseFiles)}
                        </PanelButton>

                        {staged.length > 0 && (
                            <PanelButton
                                primary
                                disabled={enhancing !== null}
                                onClick={() => {
                                    void upload();
                                }}
                            >
                                <Upload className="size-3.5" aria-hidden="true" />
                                {t((d) => d.documentsCapture.uploadCount).replace('{count}', String(staged.length))}
                            </PanelButton>
                        )}
                    </>
                }
            >
                {staged.length > 0 && (
                    <div className="mt-3">
                        {/* The grouping is a tick per file rather than a mode, because one pick is often
                        both: five separate receipts and one invoice photographed across three pages. */}
                        <p className="note mb-2">{t((d) => d.documentsCapture.stagedHint)}</p>

                        {/* Said plainly once two are ticked, because the consequence of the ticks is the
                        one thing that cannot be changed after the upload starts. */}
                        {groupedCount > 1 && (
                            <p className="note mb-2 font-medium">
                                {t((d) => d.documentsCapture.groupedNotice).replace('{count}', String(groupedCount))}
                            </p>
                        )}

                        <ul className="grid gap-1.5">
                            {staged.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="tile border-border flex flex-wrap items-center gap-2 border p-2"
                                >
                                    {/* What the file is, before its name: a phone calls every photograph
                                    IMG_0042, so the picture identifies it and the name does not. */}
                                    {entry.preview === undefined ? (
                                        <span className="row-mark">
                                            <FileText className="size-4" aria-hidden="true" />
                                        </span>
                                    ) : (
                                        <img
                                            src={entry.preview}
                                            alt={entry.file.name}
                                            className="h-12 w-12 shrink-0 rounded-lg border border-border object-cover"
                                        />
                                    )}

                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-xs">{entry.file.name}</span>
                                        <span className="note tabular-nums">
                                            {formatBytes(entry.file.size, locale)}
                                        </span>
                                        {/* The label is written out, not left to a bare tick: someone
                                        meeting this form for the first time has no way to guess what
                                        ticking an unlabelled box next to a file would do. */}
                                        <label className="mt-1 flex items-center gap-1.5 text-xs">
                                            <Checkbox
                                                checked={entry.grouped}
                                                onCheckedChange={(next) => {
                                                    setStaged((current) =>
                                                        current.map((candidate) =>
                                                            candidate.id === entry.id
                                                                ? { ...candidate, grouped: next }
                                                                : candidate,
                                                        ),
                                                    );
                                                }}
                                                aria-label={t((d) => d.documentsCapture.groupThisFile)}
                                            />
                                            {t((d) => d.documentsCapture.groupThisFile)}
                                        </label>
                                    </span>

                                    {canEnhance && entry.file.type.startsWith('image/') && (
                                        <PanelButton
                                            disabled={enhancing !== null || entry.enhanced === true}
                                            onClick={() => {
                                                void enhance(entry);
                                            }}
                                        >
                                            <Wand2 className="size-3.5" aria-hidden="true" />
                                            {entry.enhanced === true
                                                ? t((d) => d.documentsCapture.enhanced)
                                                : t((d) => d.documentsCapture.enhance)}
                                        </PanelButton>
                                    )}

                                    <PanelButton
                                        variant="ghost"
                                        onClick={() => {
                                            setStaged((current) =>
                                                current.filter((candidate) => candidate.id !== entry.id),
                                            );
                                        }}
                                    >
                                        {t((d) => d.documentsCapture.remove)}
                                    </PanelButton>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <ErrorText message={pickError} />

                {/* Two inputs rather than one: `capture="environment"` opens the rear camera directly on
                a phone, which is the whole point of photographing a receipt, while the second
                accepts several existing files including PDFs. A single input cannot do both. */}
                {canTakePhoto && (
                    <input
                        ref={cameraInputRef}
                        type="file"
                        accept="image/*"
                        capture="environment"
                        className="hidden"
                        aria-hidden="true"
                        tabIndex={-1}
                        onChange={(event) => {
                            handleFiles(event);
                        }}
                    />
                )}
                <input
                    ref={fileInputRef}
                    type="file"
                    accept={accepted}
                    multiple
                    className="hidden"
                    aria-hidden="true"
                    tabIndex={-1}
                    onChange={(event) => {
                        handleFiles(event);
                    }}
                />

                <div className="mt-4">
                    <div className="eyebrow mb-2 flex items-center justify-between">
                        <span>{t((d) => d.documentsCapture.queueTitle)}</span>
                        {online ? null : <Status tone="warn">{t((d) => d.documentsCapture.offline)}</Status>}
                        {processing ? (
                            <Status tone="idle">{t((d) => d.documentsCapture.queueProcessing)}</Status>
                        ) : null}
                        {/* Where the batch stands. For several files this is the number that matters —
                        a per-file bar says nothing about how much of the pick is left to go. */}
                        {batchTotal > 1 && outstanding > 0 && (
                            <span className="note tabular-nums">
                                {t((d) => d.documentsCapture.batchProgress)
                                    .replace('{done}', String(batchDone))
                                    .replace('{total}', String(batchTotal))}
                            </span>
                        )}
                    </div>

                    {items.length === 0 ? (
                        <p className="note">
                            {online
                                ? t((d) => d.documentsCapture.queueEmpty)
                                : t((d) => d.documentsCapture.offlineNotice)}
                        </p>
                    ) : (
                        <ul className="space-y-2">
                            {items.map((item) => {
                                // Read out before the JSX so the null check narrows the key; a
                                // `reason ?? ''` would widen it back to a string the dictionary has no
                                // entry for.
                                const reason = item.rejectionReason;
                                const message =
                                    item.status === 'duplicate'
                                        ? t((d) => d.documentsCapture.duplicateNote)
                                        : reason === null
                                          ? (item.errorMessage ?? null)
                                          : t((d) => d.rejectionReason[reason]);

                                const fraction = progress[item.localId];
                                const percent = Math.round((fraction ?? 0) * 100);

                                return (
                                    <li key={item.localId} className="tile border-border border p-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold break-all">{queuedName(item)}</p>
                                                <p className="note">
                                                    <span className="tabular-nums">
                                                        {formatBytes(queuedSize(item), locale)}
                                                    </span>
                                                    {item.files.length > 1 && (
                                                        <>
                                                            {' · '}
                                                            {t((d) => d.documentsCapture.pageCount).replace(
                                                                '{count}',
                                                                String(item.files.length),
                                                            )}
                                                        </>
                                                    )}
                                                </p>
                                                {message === null || message === '' ? null : (
                                                    <p className="text-destructive mt-1 text-xs">{message}</p>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Status tone={QUEUE_STATUS_TONES[item.status]}>
                                                    {t((d) => d.documentsCapture[QUEUE_STATUS_KEYS[item.status]])}
                                                </Status>
                                                {item.status === 'failed' ? (
                                                    <PanelButton
                                                        onClick={() => {
                                                            void retry(item.localId);
                                                        }}
                                                    >
                                                        {t((d) => d.documentsCapture.retry)}
                                                    </PanelButton>
                                                ) : null}
                                                {item.status === 'failed' ||
                                                item.status === 'rejected' ||
                                                item.status === 'duplicate' ? (
                                                    <PanelButton
                                                        variant="ghost"
                                                        onClick={() => {
                                                            void remove(item.localId);
                                                        }}
                                                    >
                                                        {t((d) => d.documentsCapture.remove)}
                                                    </PanelButton>
                                                ) : null}
                                            </div>
                                        </div>

                                        {/* Only while bytes are actually moving. A determinate bar is
                                        drawn when the browser could measure the body; when it could
                                        not, the row still reads "uploading" rather than showing a
                                        made-up percentage. */}
                                        {item.status === 'uploading' && fraction !== undefined && (
                                            <div className="mt-2 flex items-center gap-2">
                                                <div
                                                    className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full"
                                                    role="progressbar"
                                                    aria-valuemin={0}
                                                    aria-valuemax={100}
                                                    aria-valuenow={percent}
                                                    aria-label={queuedName(item)}
                                                >
                                                    <div
                                                        className="bg-primary h-full rounded-full transition-[width] duration-150"
                                                        style={{ width: `${String(percent)}%` }}
                                                    />
                                                </div>
                                                <span className="note tabular-nums">{percent}%</span>
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </PanelDialog>

            <ConfirmDialog
                open={confirmingClose}
                onOpenChange={setConfirmingClose}
                title={t((d) => d.documentsCapture.stopUploadTitle)}
                description={t((d) => d.documentsCapture.stopUploadHint)}
                confirmLabel={t((d) => d.documentsCapture.stopUpload)}
                cancelLabel={t((d) => d.common.cancel)}
                onConfirm={() => {
                    setConfirmingClose(false);
                    onOpenChange(false);

                    return Promise.resolve();
                }}
            />
        </>
    );
}
