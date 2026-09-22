import { router } from '@inertiajs/react';
import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { readPortalAct, searchPortal } from '../../api/legislationApi';
import { Field, PanelButton, PanelDialog, PanelInput, PanelSelect, Pill } from '../../components/panel';
import { PanelDate } from '../../components/PanelDatePicker';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import { formatDate } from '../../lib/format';
import { ApiError } from '../../lib/http';
import type { PortalActResult } from '../../types/legislation';

type Mode = 'title' | 'number';

/**
 * Finds an act on legislatie.just.ro and starts reading it: by words of its title — "salariului de
 * bază minim brut", for every decision that set the minimum wage — or by its number and year, which
 * lists every act so numbered, of any type, to pick the right one.
 *
 * Reading runs as the ingest task, so the lock, the audit record and the run history are the ones
 * every task has; this is only where the act is chosen.
 */
export default function AddLawDialog({
    open,
    onOpenChange,
    onStarted,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** A read was queued: the list of laws will change once it runs. */
    onStarted: () => void;
}): JSX.Element {
    const { t, locale } = useTranslation();
    const [mode, setMode] = useState<Mode>('title');
    const [title, setTitle] = useState('');
    const [number, setNumber] = useState('');
    const [year, setYear] = useState('');
    const [from, setFrom] = useState<string | null>(null);
    const [results, setResults] = useState<PortalActResult[] | null>(null);
    const [searching, setSearching] = useState(false);
    const [started, setStarted] = useState<Set<string>>(new Set());

    const canSearch = mode === 'title' ? title.trim().length >= 3 : number !== '' && year !== '';

    const search = async (): Promise<void> => {
        setSearching(true);

        try {
            const yearValue = year === '' ? null : Number(year);

            setResults(
                await searchPortal(
                    mode === 'title'
                        ? { title: title.trim(), year: yearValue }
                        : { number: Number(number), year: yearValue ?? 0 },
                ),
            );
        } catch (cause) {
            toast.error(
                cause instanceof ApiError && cause.status === 502
                    ? t((d) => d.pages.legislation.add.portalDown)
                    : describeApiFailure(cause, t),
            );
        } finally {
            setSearching(false);
        }
    };

    const read = async (act: PortalActResult): Promise<void> => {
        try {
            await readPortalAct(act, from);
            setStarted((current) => new Set(current).add(act.url));
            toast.success(t((d) => d.pages.legislation.add.started));
            onStarted();
        } catch (cause) {
            toast.error(
                cause instanceof ApiError && cause.status === 409
                    ? t((d) => d.pages.legislation.add.busy)
                    : describeApiFailure(cause, t),
            );
        }
    };

    return (
        <PanelDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            title={t((d) => d.pages.legislation.add.title)}
            description={t((d) => d.pages.legislation.add.hint)}
            actions={
                <>
                    <PanelButton
                        onClick={() => {
                            onOpenChange(false);
                        }}
                    >
                        {t((d) => d.pages.legislation.add.close)}
                    </PanelButton>
                    <PanelButton primary disabled={!canSearch || searching} onClick={() => void search()}>
                        {t((d) => d.pages.legislation.add.search)}
                    </PanelButton>
                </>
            }
        >
            <form
                className="grid gap-3 sm:grid-cols-[10rem_1fr_7rem]"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (canSearch && !searching) {
                        void search();
                    }
                }}
            >
                <Field label={t((d) => d.pages.legislation.add.by)} htmlFor="add-law-mode">
                    <PanelSelect
                        id="add-law-mode"
                        value={mode}
                        onValueChange={(value) => {
                            setMode(value === 'number' ? 'number' : 'title');
                            setResults(null);
                        }}
                        options={[
                            { value: 'title', label: t((d) => d.pages.legislation.add.byTitle) },
                            { value: 'number', label: t((d) => d.pages.legislation.add.byNumber) },
                        ]}
                    />
                </Field>
                {mode === 'title' ? (
                    <Field label={t((d) => d.pages.legislation.add.words)} htmlFor="add-law-title">
                        <PanelInput
                            id="add-law-title"
                            type="search"
                            maxLength={200}
                            placeholder={t((d) => d.pages.legislation.add.wordsPlaceholder)}
                            value={title}
                            onChange={(event) => {
                                setTitle(event.target.value);
                            }}
                        />
                    </Field>
                ) : (
                    <Field label={t((d) => d.pages.legislation.add.number)} htmlFor="add-law-number">
                        <PanelInput
                            id="add-law-number"
                            type="number"
                            min={1}
                            max={99999}
                            value={number}
                            onChange={(event) => {
                                setNumber(event.target.value);
                            }}
                        />
                    </Field>
                )}
                <Field
                    label={t((d) => d.pages.legislation.add.year)}
                    hint={mode === 'title' ? t((d) => d.pages.legislation.add.yearOptional) : undefined}
                    htmlFor="add-law-year"
                >
                    <PanelInput
                        id="add-law-year"
                        type="number"
                        max={new Date().getFullYear()}
                        value={year}
                        onChange={(event) => {
                            setYear(event.target.value);
                        }}
                    />
                </Field>
                {/* Enter in a field searches; the dialog's own button does the same. */}
                <button type="submit" hidden aria-hidden="true" tabIndex={-1} />
            </form>

            <Field
                label={t((d) => d.pages.legislation.add.from)}
                hint={t((d) => d.pages.legislation.add.fromHint)}
                className="mt-3 max-w-xs"
            >
                <PanelDate block value={from} onChange={setFrom} />
            </Field>

            <div className="mt-4 space-y-2" aria-live="polite">
                {results === null ? null : results.length === 0 ? (
                    <p className="note">{t((d) => d.pages.legislation.add.none)}</p>
                ) : (
                    results.map((act) => (
                        <article
                            key={act.url}
                            className="tile border-border flex flex-wrap items-start justify-between gap-2 border p-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-semibold text-balance">{act.title}</p>
                                <div className="mt-1 flex flex-wrap gap-1.5">
                                    <Pill tone="accent">{act.typeLabel}</Pill>
                                    <Pill>
                                        nr. {act.number}
                                        {act.year === null ? '' : `/${String(act.year)}`}
                                    </Pill>
                                    {act.issuer === null ? null : <Pill>{act.issuer}</Pill>}
                                    {act.inForceFrom === null ? null : (
                                        <Pill tone="locked">
                                            {t((d) => d.pages.legislation.add.inForce).replace(
                                                '{date}',
                                                formatDate(act.inForceFrom, locale),
                                            )}
                                        </Pill>
                                    )}
                                </div>
                            </div>
                            {act.lawId !== null ? (
                                <PanelButton
                                    onClick={() => {
                                        router.visit(`/legislation/${act.lawId ?? ''}`);
                                    }}
                                >
                                    {t((d) => d.pages.legislation.add.open)}
                                </PanelButton>
                            ) : started.has(act.url) ? (
                                <Pill>{t((d) => d.pages.legislation.add.queued)}</Pill>
                            ) : act.importable ? (
                                <PanelButton primary onClick={() => void read(act)}>
                                    {t((d) => d.pages.legislation.add.read)}
                                </PanelButton>
                            ) : (
                                <Pill>{t((d) => d.pages.legislation.add.notReadable)}</Pill>
                            )}
                        </article>
                    ))
                )}
            </div>
        </PanelDialog>
    );
}
