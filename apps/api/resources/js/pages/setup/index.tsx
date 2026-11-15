import { useEffect, useState, type JSX } from 'react';
import { CircleCheckBig, ListChecks, TriangleAlert } from 'lucide-react';

import { getReadiness } from '../../api/setupApi';
import { EmptyState, Page, Panel, Pill, Status, TableSkeleton } from '../../components/panel';
import { useTranslation } from '../../i18n';
import type { Readiness, ReadinessRequirement } from '../../types/setup';

/**
 * The install readiness checklist (ADR 0027). A package declares the legislation it needs;
 * `GET /api/v1/setup/requirements` resolves each need and rolls the answers up to whether the
 * install is bootstrapped and each feature available. `RequireBootstrapped` sends an authenticated
 * person here until it is.
 *
 * The screen reads whatever the endpoint declares — it does not know any one package's requirements
 * by name. The requirement key and the package that declared it are shown verbatim, as provenance;
 * only the generic vocabulary (the tier, the kind of each need, the states) is translated, so a new
 * package's requirement appears here the day it is declared, with no change to this file.
 */
export default function Setup(): JSX.Element {
    const { t } = useTranslation();

    const [loaded, setLoaded] = useState<Readiness | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let active = true;

        getReadiness().then(
            (readiness) => {
                if (active) {
                    setLoaded(readiness);
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
    }, []);

    if (failed) {
        return (
            <Page title={t((d) => d.setup.title)} lede={t((d) => d.setup.lede)}>
                <Panel title={t((d) => d.setup.title)} icon={<ListChecks className="size-4" />}>
                    <EmptyState icon={<TriangleAlert className="size-5" />} title={t((d) => d.setup.loadError)} />
                </Panel>
            </Page>
        );
    }

    if (loaded === null) {
        return (
            <Page title={t((d) => d.setup.title)} lede={t((d) => d.setup.lede)}>
                <Panel title={t((d) => d.setup.requirements)} icon={<ListChecks className="size-4" />} flush>
                    <TableSkeleton rows={3} />
                </Panel>
            </Page>
        );
    }

    const ready = loaded.isBootstrapped;

    return (
        <Page title={t((d) => d.setup.title)} lede={t((d) => d.setup.lede)}>
            <Panel
                title={ready ? t((d) => d.setup.readyBannerTitle) : t((d) => d.setup.notReadyBannerTitle)}
                description={ready ? t((d) => d.setup.readyBannerBody) : t((d) => d.setup.notReadyBannerBody)}
                icon={
                    ready ? (
                        <CircleCheckBig className="size-4 text-emerald-600 dark:text-emerald-400" />
                    ) : (
                        <TriangleAlert className="size-4 text-amber-600 dark:text-amber-400" />
                    )
                }
                aside={
                    <Status tone={ready ? 'ok' : 'warn'}>
                        {ready ? t((d) => d.setup.available) : t((d) => d.setup.notReadyBannerTitle)}
                    </Status>
                }
            >
                <RequirementList requirements={loaded.requirements} />
            </Panel>

            {loaded.features.length > 0 && (
                <Panel title={t((d) => d.setup.features)} icon={<ListChecks className="size-4" />}>
                    <div className="divide-border divide-y">
                        {loaded.features.map((feature) => (
                            <div
                                key={feature.feature}
                                className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-[9px] first:pt-0 last:pb-0"
                            >
                                <span className="font-mono text-xs font-semibold">{feature.feature}</span>
                                <Status tone={feature.available ? 'ok' : 'idle'}>
                                    {feature.available ? t((d) => d.setup.available) : t((d) => d.setup.unavailable)}
                                </Status>
                            </div>
                        ))}
                    </div>
                </Panel>
            )}
        </Page>
    );
}

/** The declared requirements, each with its needs — the body of the banner card. */
function RequirementList({ requirements }: { requirements: ReadinessRequirement[] }): JSX.Element {
    const { t } = useTranslation();

    if (requirements.length === 0) {
        return <p className="note">{t((d) => d.setup.empty)}</p>;
    }

    return (
        <div className="divide-border divide-y">
            {requirements.map((requirement) => (
                <div key={requirement.key} className="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">
                    <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <span className="font-mono text-xs font-semibold">{requirement.key}</span>
                            <Pill tone={requirement.tier === 'required' ? 'accent' : 'neutral'}>
                                {t(
                                    (d) =>
                                        (d.setup.tiers as Record<string, string>)[requirement.tier] ?? requirement.tier,
                                )}
                            </Pill>
                        </div>
                        <Status tone={requirement.met ? 'ok' : 'bad'}>
                            {requirement.met ? t((d) => d.setup.inPlace) : t((d) => d.setup.missing)}
                        </Status>
                    </div>

                    <div className="note">
                        {t((d) => d.setup.declaredBy)} <span className="font-mono">{requirement.declaredBy}</span>
                    </div>

                    <ul className="flex flex-col gap-1">
                        {requirement.needs.map((need) => (
                            <li
                                key={`${need.kind}:${need.argument}`}
                                className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs"
                            >
                                <Status tone={need.met ? 'ok' : 'bad'}>
                                    {t(
                                        (d) =>
                                            (d.setup.needs as Record<string, string>)[need.kind] ??
                                            d.setup.needs.unknown,
                                    )}
                                </Status>
                                <span className="font-mono text-muted-foreground">{need.argument}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </div>
    );
}
