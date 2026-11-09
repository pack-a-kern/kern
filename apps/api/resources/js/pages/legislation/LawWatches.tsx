import { Eye, Trash2 } from 'lucide-react';
import { useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';

import { changeLawWatch, deleteLawWatch, listLawWatches } from '../../api/legislationApi';
import ConfirmDialog from '../../components/ConfirmDialog';
import { EmptyState, Panel, PanelButton, PanelSelect, Pill, Row, RowList } from '../../components/panel';
import { Switch } from '../../components/ui/switch';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate } from '../../lib/format';
import { intervalLabel } from '../../lib/watchInterval';
import type { LawWatch } from '../../types/legislation';

/**
 * The searches of the portal kept and run again, each new act they find read as a law: the packages'
 * — paused here at most, since their interval is their configuration's — and those a person kept, which
 * they may also run more or less often, or stop watching. `reload` is bumped when "Add a law" keeps one.
 */
export default function LawWatches({ reload }: { reload: number }): JSX.Element {
    const { t, locale } = useTranslation();
    const words = t((d) => d.pages.legislation.watches);
    const [watches, setWatches] = useState<LawWatch[] | null>(null);
    const [intervals, setIntervals] = useState<number[]>([]);
    const [removing, setRemoving] = useState<LawWatch | null>(null);

    useEffect(() => {
        let current = true;

        listLawWatches()
            .then((answer) => {
                if (current) {
                    setWatches(answer.watches);
                    setIntervals(answer.intervals);
                }
            })
            .catch(() => {
                if (current) {
                    toast.error(words.failed);
                }
            });

        return () => {
            current = false;
        };
    }, [reload, words.failed]);

    const change = async (watch: LawWatch, changed: { enabled?: boolean; everyHours?: number }): Promise<void> => {
        try {
            const saved = await changeLawWatch(watch.id, changed);
            setWatches((all) => all?.map((one) => (one.id === saved.id ? saved : one)) ?? null);
        } catch (cause) {
            toast.error(describeApiFailure(cause, t));
        }
    };

    return (
        <Panel title={words.title} description={words.hint} icon={<Eye className="size-4" />}>
            {watches === null ? null : watches.length === 0 ? (
                <EmptyState icon={<Eye className="size-5" />} title={words.empty} />
            ) : (
                <RowList>
                    {watches.map((watch) => (
                        <Row
                            key={watch.id}
                            title={watch.searchWords}
                            subtitle={[
                                words.scope.replace('{type}', watch.actType).replace('{year}', String(watch.firstYear)),
                                intervalLabel(words, watch.everyHours),
                                watch.lastRunAt === null
                                    ? words.notRun
                                    : words.lastRun
                                          .replace('{date}', formatDate(watch.lastRunAt, locale))
                                          .replace('{result}', watch.lastResult ?? ''),
                            ].join(' · ')}
                            status={
                                <Pill {...(watch.declaredBy === null ? {} : { tone: 'accent' as const })}>
                                    {watch.declaredBy !== null
                                        ? words.declaredBy.replace('{package}', watch.declaredBy)
                                        : watch.mine
                                          ? words.yours
                                          : words.someone}
                                </Pill>
                            }
                        >
                            {watch.declaredBy === null ? (
                                <PanelSelect
                                    aria-label={words.interval}
                                    value={String(watch.everyHours)}
                                    onValueChange={(hours) => void change(watch, { everyHours: Number(hours) })}
                                    options={intervals.map((hours) => ({
                                        value: String(hours),
                                        label: intervalLabel(words, hours),
                                    }))}
                                />
                            ) : null}
                            <Switch
                                aria-label={words.active}
                                checked={watch.enabled}
                                onCheckedChange={(enabled) => void change(watch, { enabled })}
                            />
                            {watch.declaredBy === null ? (
                                <PanelButton
                                    onClick={() => {
                                        setRemoving(watch);
                                    }}
                                >
                                    <Trash2 className="size-3.5" aria-hidden="true" />
                                    {words.remove}
                                </PanelButton>
                            ) : null}
                        </Row>
                    ))}
                </RowList>
            )}

            {removing === null ? null : (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setRemoving(null);
                        }
                    }}
                    title={words.remove}
                    description={removing.searchWords}
                    detail={<p className="note">{words.removeConfirm}</p>}
                    confirmLabel={words.remove}
                    cancelLabel={words.cancel}
                    onConfirm={async () => {
                        try {
                            await deleteLawWatch(removing.id);
                            setWatches((all) => all?.filter((one) => one.id !== removing.id) ?? null);
                            toast.success(words.removed);
                            setRemoving(null);
                        } catch (cause) {
                            toast.error(describeApiFailure(cause, t));
                            throw cause;
                        }
                    }}
                />
            )}
        </Panel>
    );
}
