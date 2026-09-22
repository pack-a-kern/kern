import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { approveFiscalParameter, rejectFiscalParameter } from '../../api/fiscalParametersApi';
import { PanelButton, PanelDialog, Pill, Status } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { checkTone, describeCheck, describeFiscalValue, describePeriod } from '../../lib/fiscalValue';
import type { FiscalParameter, LawParagraph, ParameterVersion } from '../../types/fiscalParameters';

/**
 * One value, to decide: what it is, for when, what it was read from, every paragraph of the law
 * found to say it — the one to approve it on is chosen here — and what did not agree.
 */
export default function FiscalParameterDialog({
    parameter,
    version,
    canApprove,
    onClose,
    onDecided,
}: {
    parameter: FiscalParameter;
    version: ParameterVersion;
    canApprove: boolean;
    onClose: () => void;
    onDecided: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const paragraphs = uniqueParagraphs(version);
    const [chosen, setChosen] = useState(version.source?.sectionId ?? paragraphs[0]?.sectionId ?? '');
    const [busy, setBusy] = useState(false);
    const decidable = canApprove && version.status === 'CANDIDATE';
    const name = t((d) => d.pages.adminParameters.names)[parameter.code] ?? parameter.code;

    const decide = async (approve: boolean): Promise<void> => {
        setBusy(true);

        try {
            if (approve) {
                await approveFiscalParameter(version.id, chosen);
            } else {
                await rejectFiscalParameter(version.id);
            }

            toast.success(t((d) => (approve ? d.pages.adminParameters.approved : d.pages.adminParameters.rejected)));
            onDecided();
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        } finally {
            setBusy(false);
        }
    };

    return (
        <PanelDialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            size="lg"
            title={`${name} — ${describeFiscalValue(parameter.type, version.value, t, locale)}`}
            description={describePeriod(version, t, locale)}
            actions={
                <>
                    <PanelButton onClick={onClose}>{t((d) => d.pages.adminParameters.close)}</PanelButton>
                    {decidable ? (
                        <>
                            <PanelButton disabled={busy} onClick={() => void decide(false)}>
                                {t((d) => d.pages.adminParameters.reject)}
                            </PanelButton>
                            <PanelButton primary disabled={busy || chosen === ''} onClick={() => void decide(true)}>
                                {t((d) => d.pages.adminParameters.approve)}
                            </PanelButton>
                        </>
                    ) : null}
                </>
            }
        >
            <div className="modal-scroll space-y-4 text-sm">
                <p>
                    <StatusPill status={version.status} />
                </p>

                {version.checks.length === 0 ? null : (
                    <section className="space-y-1">
                        {version.checks.map((check, index) => (
                            <p key={index}>
                                <Status tone={checkTone(check)}>{describeCheck(check, t)}</Status>
                            </p>
                        ))}
                    </section>
                )}

                <section>
                    <h3 className="text-xs font-semibold">{t((d) => d.pages.adminParameters.readFrom)}</h3>
                    <ul className="mt-1 space-y-1.5">
                        {version.readings.map((reading, index) => (
                            <li key={index} className="text-muted-foreground text-xs">
                                {reading.kind === 'form' ? (
                                    <>
                                        <a className="link" href={reading.url} target="_blank" rel="noreferrer">
                                            {t((d) => d.pages.adminParameters.formFile)
                                                .replace('{form}', reading.form)
                                                .replace('{year}', String(reading.formYear))}
                                        </a>
                                        {reading.quotes.map((quote) => (
                                            <code key={quote} className="bg-muted ml-1.5 rounded px-1">
                                                {quote}
                                            </code>
                                        ))}
                                    </>
                                ) : reading.kind === 'decision' ? (
                                    <>
                                        <a className="link" href={`/legislation/${reading.lawId}`}>
                                            {reading.act} · {reading.citation}
                                        </a>
                                        <span className="ml-1.5 italic">„{reading.quote}”</span>
                                    </>
                                ) : (
                                    t((d) => d.pages.adminParameters.carried).replace(
                                        '{year}',
                                        String(reading.fromYear),
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <section>
                    <h3 className="text-xs font-semibold">{t((d) => d.pages.adminParameters.inLaw)}</h3>
                    <p className="note">{t((d) => d.pages.adminParameters.inLawHint)}</p>
                    <div
                        className="mt-2 space-y-2"
                        role="radiogroup"
                        aria-label={t((d) => d.pages.adminParameters.inLaw)}
                    >
                        {paragraphs.map((found) => (
                            <label
                                key={found.sectionId}
                                className="tile border-border flex cursor-pointer items-start gap-2 border p-2.5"
                            >
                                <input
                                    type="radio"
                                    name="source"
                                    className="mt-1"
                                    value={found.sectionId}
                                    checked={chosen === found.sectionId}
                                    disabled={!decidable}
                                    onChange={() => {
                                        setChosen(found.sectionId);
                                    }}
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">{found.citation}</span>
                                        {found.lawId === null ? null : (
                                            <a
                                                className="link text-xs"
                                                href={`/legislation/${found.lawId}${found.on === null ? '' : `?as_of=${found.on}`}`}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {t((d) => d.pages.adminParameters.openLaw)}
                                            </a>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground mt-1 block text-xs leading-relaxed">
                                        {found.excerpt}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                </section>
            </div>
        </PanelDialog>
    );
}

/** The value's own source first, then every other paragraph found, each once. */
function uniqueParagraphs(version: ParameterVersion): LawParagraph[] {
    const seen = new Set<string>();

    return [...(version.source === null ? [] : [version.source]), ...version.matches].filter((found) => {
        if (seen.has(found.sectionId)) {
            return false;
        }

        seen.add(found.sectionId);

        return true;
    });
}

export function StatusPill({ status }: { status: ParameterVersion['status'] }): JSX.Element {
    const { t } = useTranslation();

    return (
        <Pill tone={status === 'APPROVED' ? 'accent' : status === 'CANDIDATE' ? 'locked' : 'neutral'}>
            {t((d) => d.pages.adminParameters.statuses[status])}
        </Pill>
    );
}
