import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useCallback, useEffect, useState, type JSX } from 'react';
import AppShell from '../../components/AppShell';
import StatusBadge from '../../components/StatusBadge';
import ErrorText from '../../components/ErrorText';
import ListSkeleton from '../../components/ListSkeleton';
import { fetchDocument } from '../../api/documentsApi';
import { useDocumentsChannel } from '../../hooks/useDocumentsChannel';
import { useTranslation } from '../../i18n';
import { listKnownDocuments, upsertKnownDocument, type KnownDocument } from '../../lib/db';
import { getActiveSubjectId } from '../../lib/subjectStore';
import { ApiError } from '../../lib/http';
import type { DocumentLifecycleEvent } from '../../types/documents';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';

function formatDate(iso: string): string {
    try {
        return new Intl.DateTimeFormat('ro-RO', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

export default function DocumentsInbox(): JSX.Element {
    const { t } = useTranslation();
    const [subjectId] = useState(() => getActiveSubjectId());
    const [documents, setDocuments] = useState<KnownDocument[]>([]);
    const [loading, setLoading] = useState(true);

    const loadFromCache = useCallback(async () => {
        if (!subjectId) {
            setLoading(false);

            return;
        }

        setDocuments(await listKnownDocuments(subjectId));
        setLoading(false);
    }, [subjectId]);

    useEffect(() => {
        void (async () => {
            await loadFromCache();
        })();
    }, [loadFromCache]);

    // One authorised-HTTPS refresh per known non-terminal document on mount — not a poll loop
    // (there is no interval/timer here), just catching up on anything that changed while this
    // device was closed, before the live Reverb subscription below takes over.
    useEffect(() => {
        if (!subjectId) {
            return;
        }

        const cancelledRef = { current: false };

        void (async () => {
            const known = await listKnownDocuments(subjectId);

            for (const doc of known) {
                if (doc.status === 'READY' || doc.status === 'REJECTED') {
                    continue;
                }

                try {
                    const fresh = await fetchDocument(subjectId, doc.id);

                    if (cancelledRef.current) {
                        return;
                    }

                    await upsertKnownDocument({ ...doc, ...fresh, subjectId, lastSyncedAt: Date.now() });
                } catch {
                    // Best-effort catch-up; the live channel below will still deliver future changes.
                }
            }

            if (!cancelledRef.current) {
                await loadFromCache();
            }
        })();

        return () => {
            cancelledRef.current = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- runs once per mount for this subject
    }, [subjectId]);

    const handleLifecycleEvent = useCallback(
        (event: DocumentLifecycleEvent) => {
            if (!subjectId) {
                return;
            }

            void (async () => {
                try {
                    const fresh = await fetchDocument(subjectId, event.documentId);

                    await upsertKnownDocument({
                        id: fresh.id,
                        subjectId,
                        type: fresh.type,
                        status: fresh.status,
                        rejectionReason: fresh.rejectionReason,
                        mimeType: fresh.mimeType,
                        sizeBytes: fresh.sizeBytes,
                        receivedAt: fresh.receivedAt,
                        lastSyncedAt: Date.now(),
                    });
                    await loadFromCache();
                } catch (cause) {
                    if (cause instanceof ApiError && cause.status === 401) {
                        window.location.assign('/login');

                        return;
                    }
                    // A document this device doesn't know about yet (uploaded elsewhere) or a
                    // transient fetch error — nothing to reconcile locally either way.
                }
            })();
        },
        [subjectId, loadFromCache],
    );

    useDocumentsChannel(subjectId, handleLifecycleEvent);

    if (!subjectId) {
        return (
            <AppShell>
                <Head title={t((d) => d.documentsInbox.title)} />
                <ErrorText message={t((d) => d.documentsInbox.noActiveSubject)} />
                <Button variant="link" className="h-auto p-0" asChild>
                    <Link href="/dashboard">{t((d) => d.documentsInbox.goToDashboard)}</Link>
                </Button>
            </AppShell>
        );
    }

    return (
        <AppShell>
            <Head title={t((d) => d.documentsInbox.title)} />
            <div className="flex items-center justify-between">
                <h1 className="text-xl font-semibold text-foreground">{t((d) => d.documentsInbox.title)}</h1>
                <Button asChild>
                    <Link href="/documents/capture">
                        <Plus /> {t((d) => d.documentsInbox.newDocument)}
                    </Link>
                </Button>
            </div>

            <Alert>
                <AlertDescription>{t((d) => d.documentsInbox.liveNotice)}</AlertDescription>
            </Alert>

            {loading && <ListSkeleton />}
            {!loading && documents.length === 0 && (
                <p className="rounded-md border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                    {t((d) => d.documentsInbox.empty)}
                </p>
            )}

            <ul className="space-y-2">
                {documents.map((doc) => (
                    <li key={doc.id} className="rounded-lg border border-border bg-card p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p className="text-sm font-medium text-foreground">
                                    {t((d) => d.documentType[doc.type])}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t((d) => d.documentsInbox.received)}:{' '}
                                    <time dateTime={doc.receivedAt} className="tabular-nums">
                                        {formatDate(doc.receivedAt)}
                                    </time>{' '}
                                    · <span className="tabular-nums">{(doc.sizeBytes / 1024).toFixed(0)}</span> KB
                                </p>
                                {doc.rejectionReason &&
                                    (() => {
                                        const reason = doc.rejectionReason;

                                        return (
                                            <p className="mt-1 text-xs text-destructive">
                                                {t((d) => d.rejectionReason[reason])}
                                            </p>
                                        );
                                    })()}
                            </div>
                            <div className="flex items-center gap-2">
                                <StatusBadge status={doc.status} />
                                {doc.status === 'READY' && (
                                    <Button variant="link" className="h-auto p-0" asChild>
                                        <Link href={`/documents/${doc.id}/review`}>
                                            {t((d) => d.documentsInbox.review)}
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </AppShell>
    );
}
