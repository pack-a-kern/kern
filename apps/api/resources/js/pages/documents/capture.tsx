import { Head, Link } from '@inertiajs/react';
import { Camera, FolderOpen } from 'lucide-react';
import { useRef, useState, type ChangeEvent, type JSX } from 'react';
import AppShell from '../../components/AppShell';
import ErrorText from '../../components/ErrorText';
import { useOnlineStatus } from '../../hooks/useOnlineStatus';
import { useUploadQueue } from '../../hooks/useUploadQueue';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import type { CaptureStatus } from '../../lib/db';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { DOCUMENT_TYPES, type DocumentType } from '../../types/documents';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';

const QUEUE_STATUS_KEYS: Record<CaptureStatus, keyof Dictionary['documentsCapture']> = {
    queued: 'statusQueued',
    uploading: 'statusUploading',
    uploaded: 'statusUploaded',
    failed: 'statusFailed',
    rejected: 'statusRejected',
};

export default function DocumentCapture(): JSX.Element {
    const { t } = useTranslation();
    const [subjectId] = useState(() => getActiveSubjectId());
    const [type, setType] = useState<DocumentType>('RECEIPT');
    const [pickError, setPickError] = useState<string | null>(null);
    const online = useOnlineStatus();
    const { items, enqueue, retry, remove, processing } = useUploadQueue(subjectId);

    const cameraInputRef = useRef<HTMLInputElement | null>(null);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    async function handleFiles(event: ChangeEvent<HTMLInputElement>): Promise<void> {
        setPickError(null);
        const files = event.target.files;
        event.target.value = '';

        if (!files || files.length === 0) {
            return;
        }

        try {
            for (const file of Array.from(files)) {
                await enqueue(type, file);
            }
        } catch (error) {
            setPickError(error instanceof Error ? error.message : t((d) => d.documentsCapture.pickError));
        }
    }

    if (!subjectId) {
        return (
            <AppShell>
                <Head title={t((d) => d.documentsCapture.title)} />
                <ErrorText message={t((d) => d.documentsCapture.noActiveSubject)} />
                <Button variant="link" className="h-auto p-0" asChild>
                    <Link href="/dashboard">{t((d) => d.documentsCapture.goToDashboard)}</Link>
                </Button>
            </AppShell>
        );
    }

    return (
        <AppShell>
            <Head title={t((d) => d.documentsCapture.title)} />
            <div>
                <h1 className="mb-2 text-xl font-semibold text-foreground">{t((d) => d.documentsCapture.title)}</h1>
                <p className="text-sm text-muted-foreground">{t((d) => d.documentsCapture.intro)}</p>
            </div>

            {!online && (
                <Alert className="border-warning/30 bg-warning/10">
                    <AlertDescription className="text-warning-foreground">
                        {t((d) => d.documentsCapture.offlineNotice)}
                    </AlertDescription>
                </Alert>
            )}

            <Card>
                <CardContent className="space-y-4">
                    <Field>
                        <FieldLabel htmlFor="document-type">{t((d) => d.documentsCapture.typeLabel)}</FieldLabel>
                        <Select
                            value={type}
                            onValueChange={(value) => {
                                setType(value as DocumentType);
                            }}
                        >
                            <SelectTrigger id="document-type" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {DOCUMENT_TYPES.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {t((d) => d.documentType[value])}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <ErrorText message={pickError} />

                    <div className="flex flex-wrap gap-3">
                        <Button type="button" className="flex-1" onClick={() => cameraInputRef.current?.click()}>
                            <Camera /> {t((d) => d.documentsCapture.takePhoto)}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="flex-1"
                            onClick={() => fileInputRef.current?.click()}
                        >
                            <FolderOpen /> {t((d) => d.documentsCapture.chooseFiles)}
                        </Button>
                    </div>

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
                </CardContent>
            </Card>

            <div>
                <h2 className="mb-2 text-sm font-semibold text-foreground">
                    {t((d) => d.documentsCapture.queueTitle)}{' '}
                    {processing && (
                        <span className="font-normal text-muted-foreground">
                            ({t((d) => d.documentsCapture.queueProcessing)})
                        </span>
                    )}
                </h2>

                {items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t((d) => d.documentsCapture.queueEmpty)}</p>
                ) : (
                    <ul className="space-y-2">
                        {items.map((item) => (
                            <li key={item.localId} className="rounded-lg border border-border bg-card p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="text-sm font-medium text-foreground">
                                            {t((d) => d.documentType[item.type])}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.fileName} ·{' '}
                                            <span className="tabular-nums">{(item.sizeBytes / 1024).toFixed(0)}</span>{' '}
                                            KB
                                        </p>
                                        {(() => {
                                            const reason = item.rejectionReason;
                                            const message = reason
                                                ? t((d) => d.rejectionReason[reason])
                                                : (item.errorMessage ??
                                                  t((d) => d.documentsCapture.uploadFailedRetrying));

                                            return (
                                                message && <p className="mt-1 text-xs text-destructive">{message}</p>
                                            );
                                        })()}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline">
                                            {t((d) => d.documentsCapture[QUEUE_STATUS_KEYS[item.status]])}
                                        </Badge>
                                        {item.status === 'failed' && (
                                            <Button
                                                variant="link"
                                                className="h-auto p-0"
                                                onClick={() => void retry(item.localId)}
                                            >
                                                {t((d) => d.documentsCapture.retry)}
                                            </Button>
                                        )}
                                        {(item.status === 'failed' || item.status === 'rejected') && (
                                            <Button
                                                variant="link"
                                                className="h-auto p-0 text-muted-foreground"
                                                onClick={() => void remove(item.localId)}
                                            >
                                                {t((d) => d.documentsCapture.remove)}
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppShell>
    );
}
