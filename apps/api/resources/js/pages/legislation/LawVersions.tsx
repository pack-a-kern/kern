import { Check, ExternalLink, Eye } from 'lucide-react';
import type { JSX } from 'react';

import { PanelButton, Status } from '../../components/panel';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '../../components/ui/sheet';
import { useTranslation } from '../../i18n';
import { formatDate } from '../../lib/format';
import { cn } from '../../lib/utils';
import type { LawDetail } from '../../types/legislation';

/**
 * Every consolidation the portal lists, newest first, in a panel beside the text rather than a long
 * table under it: the one shown is marked, any other read one opens the law as it stood then, and
 * each links to its page on the portal.
 */
export default function LawVersions({
    law,
    shown,
    open,
    onOpenChange,
    onView,
}: {
    law: LawDetail;
    /** The day the consolidation on screen applies from. */
    shown: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onView: (date: string) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full gap-0 p-0 sm:max-w-md">
                <SheetHeader className="border-border border-b p-4">
                    <SheetTitle>{t((d) => d.pages.legislation.law.consolidationsTitle)}</SheetTitle>
                    <SheetDescription>{t((d) => d.pages.legislation.law.consolidationsHint)}</SheetDescription>
                </SheetHeader>
                <ol className="overflow-y-auto px-4 py-2">
                    {law.consolidations.map((consolidation) => {
                        const current = consolidation.date === shown;

                        return (
                            <li
                                key={consolidation.ref}
                                className="border-border grid grid-cols-[0.75rem_minmax(0,1fr)_auto] items-start gap-2.5 border-b py-3 last:border-b-0"
                            >
                                <span
                                    className={cn(
                                        'mt-1.5 size-2 rounded-full',
                                        current ? 'bg-emerald-500' : 'bg-muted-foreground/30',
                                    )}
                                />
                                <div className="min-w-0">
                                    <p className="text-xs font-semibold tabular-nums">
                                        {formatDate(consolidation.date, locale)}
                                        {consolidation.effectiveUntil === null
                                            ? ''
                                            : ` – ${formatDate(consolidation.effectiveUntil, locale)}`}
                                    </p>
                                    <p className="mt-0.5">
                                        {consolidation.failed ? (
                                            <Status tone="bad">{t((d) => d.pages.legislation.law.failed)}</Status>
                                        ) : consolidation.versionId === null ? (
                                            <Status tone="idle">{t((d) => d.pages.legislation.law.notRead)}</Status>
                                        ) : (
                                            <span className="note">
                                                {t((d) => d.pages.legislation.law.sections).replace(
                                                    '{count}',
                                                    String(consolidation.sectionCount ?? 0),
                                                )}
                                            </span>
                                        )}
                                    </p>
                                    <a
                                        className="text-info mt-1 inline-flex items-center gap-1 text-xs"
                                        href={consolidation.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {t((d) => d.pages.legislation.law.onPortal)}
                                        <ExternalLink className="size-3" />
                                    </a>
                                </div>
                                {consolidation.versionId === null ? null : (
                                    <PanelButton
                                        disabled={current}
                                        onClick={() => {
                                            onView(consolidation.date);
                                            onOpenChange(false);
                                        }}
                                    >
                                        {current ? (
                                            <Check className="size-3.5" aria-hidden="true" />
                                        ) : (
                                            <Eye className="size-3.5" aria-hidden="true" />
                                        )}
                                        {current
                                            ? t((d) => d.pages.legislation.law.shown)
                                            : t((d) => d.pages.legislation.law.viewOn)}
                                    </PanelButton>
                                )}
                            </li>
                        );
                    })}
                </ol>
            </SheetContent>
        </Sheet>
    );
}
