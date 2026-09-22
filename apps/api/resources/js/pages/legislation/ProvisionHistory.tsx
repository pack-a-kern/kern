import { useEffect, useState, type JSX } from 'react';

import { getProvisionHistory } from '../../api/legislationApi';
import { PanelButton, PanelDialog, Pill, Status } from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import { formatDate } from '../../lib/format';
import { withTables } from '../../lib/lawText';
import { wordDiff } from '../../lib/wordDiff';
import type { LawSection, ProvisionVersion } from '../../types/legislation';
import { ProvisionNotes, SectionBody } from './LawArticle';

/** Each wording the provision has had, newest first, with what changed from the one before. */
export default function ProvisionHistory({
    lawId,
    section,
    onClose,
}: {
    lawId: string;
    section: LawSection | null;
    onClose: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const citation = section?.citation ?? null;
    const [answer, setAnswer] = useState<{ citation: string; versions: ProvisionVersion[] | null } | null>(null);
    const current = answer !== null && answer.citation === citation ? answer : null;
    const versions = current?.versions ?? null;
    const failed = current !== null && current.versions === null;

    useEffect(() => {
        if (citation === null) {
            return;
        }

        let active = true;

        getProvisionHistory(lawId, citation).then(
            (loaded) => {
                if (active) {
                    setAnswer({ citation, versions: loaded });
                }
            },
            () => {
                if (active) {
                    setAnswer({ citation, versions: null });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [lawId, citation]);

    const date = (value: string): string => formatDate(value, locale);

    return (
        <PanelDialog
            open={section !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            size="lg"
            title={t((d) => d.pages.legislation.law.historyTitle).replace('{citation}', section?.citation ?? '')}
            description={t((d) => d.pages.legislation.law.historyHint)}
            actions={<PanelButton onClick={onClose}>{t((d) => d.pages.legislation.law.close)}</PanelButton>}
        >
            {failed ? (
                <p className="note">{t((d) => d.pages.legislation.law.historyFailed)}</p>
            ) : versions === null ? (
                <Skeleton className="tile h-24 w-full" />
            ) : versions.length === 0 ? (
                <p className="note">{t((d) => d.pages.legislation.law.historyEmpty)}</p>
            ) : (
                <div className="modal-scroll space-y-4">
                    {versions.map((version, index) => {
                        const previous = versions[index + 1];

                        return (
                            <article key={version.versionIndex} className="tile border-border border p-3">
                                <div className="flex flex-wrap items-center gap-2 text-xs font-semibold tabular-nums">
                                    {(version.validUntil === null
                                        ? t((d) => d.pages.legislation.law.heldOpen)
                                        : t((d) => d.pages.legislation.law.heldClosed)
                                    )
                                        .replace('{from}', date(version.validFrom))
                                        .replace(
                                            '{until}',
                                            version.validUntil === null ? '' : date(version.validUntil),
                                        )}
                                    {version.datedByNote ? (
                                        <Pill tone="accent">{t((d) => d.pages.legislation.law.datedByNote)}</Pill>
                                    ) : null}
                                    {version.amendedOn === null ? null : (
                                        <Pill>
                                            {t((d) => d.pages.legislation.law.amended).replace(
                                                '{date}',
                                                date(version.amendedOn),
                                            )}
                                        </Pill>
                                    )}
                                </div>
                                {version.coverageComplete ? null : (
                                    <p className="mt-1">
                                        <Status tone="warn">{t((d) => d.pages.legislation.law.coverageGap)}</Status>
                                    </p>
                                )}
                                <p className="note mt-2">
                                    {previous === undefined
                                        ? t((d) => d.pages.legislation.law.firstVersion)
                                        : t((d) => d.pages.legislation.law.changes)}
                                </p>
                                <ProvisionNotes notes={version.notes} />
                                {previous === undefined ? (
                                    <div className="mt-1 text-sm leading-relaxed">
                                        <SectionBody text={version.text} tables={version.tables} />
                                    </div>
                                ) : (
                                    <p className="mt-1 text-sm leading-relaxed whitespace-pre-line">
                                        {wordDiff(
                                            withTables(previous.text, previous.tables),
                                            withTables(version.text, version.tables),
                                        ).map((part, partIndex) =>
                                            part.kind === 'same' ? (
                                                <span key={partIndex}>{part.text}</span>
                                            ) : part.kind === 'added' ? (
                                                <ins key={partIndex} className="bg-emerald-500/15 no-underline">
                                                    {part.text}
                                                </ins>
                                            ) : (
                                                <del
                                                    key={partIndex}
                                                    className="bg-destructive/10 text-muted-foreground"
                                                >
                                                    {part.text}
                                                </del>
                                            ),
                                        )}
                                    </p>
                                )}
                            </article>
                        );
                    })}
                </div>
            )}
        </PanelDialog>
    );
}
