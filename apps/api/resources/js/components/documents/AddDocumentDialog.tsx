import { Camera, FolderOpen } from 'lucide-react';
import { useRef, useState, type ChangeEvent, type JSX } from 'react';

import ErrorText from '../ErrorText';
import { Field, FieldGrid, PanelButton, PanelDialog, PanelSelect, Status, type StatusTone } from '../panel';
import { useOnlineStatus } from '../../hooks/useOnlineStatus';
import { useCanTakePhoto } from '../../hooks/useMediaQuery';
import { useUploadQueue } from '../../hooks/useUploadQueue';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import type { CaptureStatus } from '../../lib/db';
import { takeSelectedFiles } from '../../lib/fileInput';
import { formatBytes } from '../../lib/format';
import { DOCUMENT_TYPES, type DocumentType } from '../../types/documents';

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
    const [type, setType] = useState<DocumentType>('RECEIPT');
    const [pickError, setPickError] = useState<string | null>(null);
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

    async function handleFiles(event: ChangeEvent<HTMLInputElement>): Promise<void> {
        setPickError(null);

        // Copied out of the live `FileList` before the input is reset — see `takeSelectedFiles`.
        // Reading it afterwards yields an empty list and the picker appears to do nothing at all.
        const selected = takeSelectedFiles(event.target);

        if (selected.length === 0) {
            return;
        }

        // A pick that arrives while the queue is still draining joins the batch in progress; one
        // that arrives after it has emptied starts a new count, rather than adding to a total
        // nobody is watching any more.
        setBatchTotal((current) => (outstanding === 0 ? selected.length : current + selected.length));

        try {
            for (const file of selected) {
                await enqueue(type, file);
            }
        } catch {
            // The page's own translated copy, never `error.message`: everything `enqueue` throws is
            // a local failure carrying a developer string, and rendering that would put untranslated
            // text on screen. Server-side rejections travel a different path — they are stored on
            // the queued item and shown against it with their own reason.
            setPickError(t((d) => d.documentsCapture.pickError));
        }
    }

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t((d) => d.documentsCapture.addTitle)}
            description={t((d) => d.documentsCapture.intro)}
            size="lg"
            actions={
                <PanelButton
                    onClick={() => {
                        onOpenChange(false);
                    }}
                >
                    {t((d) => d.common.close)}
                </PanelButton>
            }
        >
            {/* The type and the buttons act on the same decision — what am I adding — so they sit on
                one row where there is width for it, and stack only when there is not. */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div className="min-w-0 flex-1">
                    <FieldGrid>
                        <Field
                            label={t((d) => d.documentsCapture.typeLabel)}
                            htmlFor="document-type"
                            hint={t((d) => d.documentsCapture.typeHint)}
                        >
                            <PanelSelect
                                id="document-type"
                                value={type}
                                onValueChange={(value) => {
                                    setType(value as DocumentType);
                                }}
                                options={DOCUMENT_TYPES.map((value) => ({
                                    value,
                                    label: t((d) => d.documentType[value]),
                                }))}
                            />
                        </Field>
                    </FieldGrid>
                </div>

                <div className="flex flex-wrap gap-2 sm:pb-[1.4rem]">
                    {/* Only where `capture` means anything. A desktop browser ignores it and opens
                        the ordinary picker, making this a second "Choose files" with a camera icon. */}
                    {canTakePhoto && (
                        <PanelButton
                            primary
                            inline
                            onClick={() => {
                                cameraInputRef.current?.click();
                            }}
                        >
                            <Camera className="size-3.5" aria-hidden="true" />
                            {t((d) => d.documentsCapture.takePhoto)}
                        </PanelButton>
                    )}
                    <PanelButton
                        primary={!canTakePhoto}
                        inline
                        onClick={() => {
                            fileInputRef.current?.click();
                        }}
                    >
                        <FolderOpen className="size-3.5" aria-hidden="true" />
                        {t((d) => d.documentsCapture.chooseFiles)}
                    </PanelButton>
                </div>
            </div>

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
                    onChange={(event) => void handleFiles(event)}
                />
            )}
            <input
                ref={fileInputRef}
                type="file"
                accept="image/*,application/pdf"
                multiple
                className="hidden"
                aria-hidden="true"
                tabIndex={-1}
                onChange={(event) => void handleFiles(event)}
            />

            <div className="mt-4">
                <div className="eyebrow mb-2 flex items-center justify-between">
                    <span>{t((d) => d.documentsCapture.queueTitle)}</span>
                    {online ? null : <Status tone="warn">{t((d) => d.documentsCapture.offline)}</Status>}
                    {processing ? <Status tone="idle">{t((d) => d.documentsCapture.queueProcessing)}</Status> : null}
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
                        {online ? t((d) => d.documentsCapture.queueEmpty) : t((d) => d.documentsCapture.offlineNotice)}
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
                                            <p className="text-xs font-semibold break-all">{item.fileName}</p>
                                            <p className="note">
                                                {t((d) => d.documentType[item.type])} ·{' '}
                                                <span className="tabular-nums">
                                                    {formatBytes(item.sizeBytes, locale)}
                                                </span>
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
                                                aria-label={item.fileName}
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
    );
}
