import { useCallback, useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { listDocumentAiConsents, listSubjects, setDocumentAiConsent } from '../../api/accountApi';
import AppShell from '../../components/AppShell';
import { Page, Panel, PanelButton, Pill, Row, RowList, Status } from '../../components/panel';
import { Switch } from '../../components/ui/switch';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { ApiError } from '../../lib/http';
import type { TaxSubject } from '../../types/account';

/**
 * What leaves this installation, and what never does.
 *
 * The only content that can be sent to a third party is a scanned document with no readable text
 * layer, and only for a tax subject whose owner has consented — per subject, not per account
 * (`docs/adr/0005-external-document-ai.md`). Consent is off until granted and revocable here.
 *
 * The second card is the more important half: it states what is *not* optional. An administrator
 * asking "where does my data go?" is owed the whole answer, and a screen that lists only the
 * toggles implies everything else is negotiable.
 */
export default function SettingsPrivacy(): JSX.Element {
    const { t } = useTranslation();

    const [subjects, setSubjects] = useState<TaxSubject[] | null>(null);
    const [consent, setConsent] = useState<Record<string, boolean>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloadToken, setReloadToken] = useState(0);

    const describeFailure = useCallback(
        (cause: unknown): string =>
            cause instanceof ApiError && cause.reason === 'network'
                ? t((d) => d.errors.network)
                : t((d) => d.errors.unexpected),
        [t],
    );

    useEffect(() => {
        let active = true;

        // Both, together: the switches must show the state that is actually stored. Rendering them
        // all "off" until touched would tell someone their consent had been revoked when it had
        // not — the exact misreading a consent screen must not produce.
        Promise.all([listSubjects(), listDocumentAiConsents()])
            .then(([loaded, consented]) => {
                if (active) {
                    setSubjects(loaded);
                    setConsent(Object.fromEntries(loaded.map((s) => [s.id, consented.has(s.id)])));
                    setFailed(false);
                }
            })
            .catch((cause: unknown) => {
                if (active) {
                    setSubjects([]);
                    setFailed(true);
                    toast.error(
                        t((d) => d.pages.settingsPrivacy.loadFailed),
                        {
                            description: describeFailure(cause),
                        },
                    );
                }
            });

        return () => {
            active = false;
        };
    }, [reloadToken, describeFailure, t]);

    const toggle = async (subject: TaxSubject, next: boolean): Promise<void> => {
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

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.settingsPrivacy.title)}
                lede={t((d) => d.pages.settingsPrivacy.description)}
                actions={
                    failed ? (
                        <PanelButton
                            onClick={() => {
                                setSubjects(null);
                                setFailed(false);
                                setReloadToken((token) => token + 1);
                            }}
                        >
                            {t((d) => d.pages.settingsPrivacy.retry)}
                        </PanelButton>
                    ) : undefined
                }
            >
                <div className="columns-1 gap-3.5 xl:columns-2">
                    <Panel
                        title={t((d) => d.pages.settingsPrivacy.consentTitle)}
                        description={t((d) => d.pages.settingsPrivacy.consentDescription)}
                    >
                        {subjects === null ? (
                            <Skeleton className="tile h-24 w-full" />
                        ) : subjects.length === 0 ? (
                            <p className="note">{t((d) => d.pages.settingsPrivacy.noSubjects)}</p>
                        ) : (
                            <RowList>
                                {subjects.map((subject) => (
                                    <Row
                                        key={subject.id}
                                        title={subject.displayName}
                                        subtitle={t((d) => d.pages.settingsPrivacy.consentRowHint)}
                                    >
                                        <Switch
                                            aria-label={subject.displayName}
                                            checked={consent[subject.id] === true}
                                            disabled={busy === subject.id || subject.role !== 'OWNER'}
                                            onCheckedChange={(checked) => {
                                                void toggle(subject, checked);
                                            }}
                                        />
                                    </Row>
                                ))}
                            </RowList>
                        )}
                        <p className="note mt-3">{t((d) => d.pages.settingsPrivacy.consentNotice)}</p>
                    </Panel>

                    <Panel
                        title={t((d) => d.pages.settingsPrivacy.guaranteesTitle)}
                        description={t((d) => d.pages.settingsPrivacy.guaranteesDescription)}
                    >
                        <RowList>
                            <Row
                                title={t((d) => d.pages.settingsPrivacy.guarantees.encryption)}
                                subtitle={t((d) => d.pages.settingsPrivacy.guarantees.encryptionHint)}
                            >
                                <Pill tone="locked">{t((d) => d.pages.settingsPrivacy.alwaysOn)}</Pill>
                            </Row>
                            <Row
                                title={t((d) => d.pages.settingsPrivacy.guarantees.localFirst)}
                                subtitle={t((d) => d.pages.settingsPrivacy.guarantees.localFirstHint)}
                            >
                                <Pill tone="locked">{t((d) => d.pages.settingsPrivacy.alwaysOn)}</Pill>
                            </Row>
                            <Row
                                title={t((d) => d.pages.settingsPrivacy.guarantees.audit)}
                                subtitle={t((d) => d.pages.settingsPrivacy.guarantees.auditHint)}
                            >
                                <Pill tone="locked">{t((d) => d.pages.settingsPrivacy.alwaysOn)}</Pill>
                            </Row>
                            <Row
                                title={t((d) => d.pages.settingsPrivacy.guarantees.logs)}
                                subtitle={t((d) => d.pages.settingsPrivacy.guarantees.logsHint)}
                            >
                                <Status tone="ok">{t((d) => d.pages.settingsPrivacy.guarantees.redacted)}</Status>
                            </Row>
                        </RowList>
                    </Panel>
                </div>
            </Page>
        </AppShell>
    );
}
