import { useState, type JSX } from 'react';
import { toast } from 'sonner';

import { setDisplayCurrency } from '../../api/walletApi';
import { useTranslation } from '../../i18n';
import { CURRENCIES } from '../../types/wallet';
import { Field, Panel, PanelSelect } from '../panel';

/**
 * Which currency this subject's totals are shown in.
 *
 * A component rather than markup on one page, because the same control belongs anywhere a total is
 * read. The setting exists to answer "roughly how much do I have" with one number, instead of
 * "€444.00 · RON 600,010.00", which answers nothing.
 *
 * Saved immediately on change: there is one field, nothing to validate against anything else, and a
 * Save button beside a single select is ceremony. A failure restores the previous value rather than
 * leaving the select showing a preference the server never accepted.
 *
 * The copy says plainly that this is a display conversion at the latest published BNR rate, and
 * never what a tax is computed from — each rule makes that decision itself, with its own provenance
 * (`BLUEPRINT.md` §7.4).
 */
export default function DisplayCurrencyCard({
    subjectId,
    value,
    onChanged,
}: {
    subjectId: string;
    value: string;
    onChanged?: (currencyCode: string) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [current, setCurrent] = useState(value);
    const [saving, setSaving] = useState(false);

    const change = async (next: string): Promise<void> => {
        const previous = current;

        setCurrent(next);
        setSaving(true);

        try {
            await setDisplayCurrency(subjectId, next);
            onChanged?.(next);
            toast.success(t((d) => d.pages.settingsSubjects.displayCurrencySaved));
        } catch {
            // Put the select back: leaving it on a value the server refused would show a preference
            // that is not in force.
            setCurrent(previous);
            toast.error(t((d) => d.pages.settingsSubjects.displayCurrencyFailed));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Panel
            title={t((d) => d.pages.settingsSubjects.displayCurrencyTitle)}
            description={t((d) => d.pages.settingsSubjects.displayCurrencyDescription)}
            footNote={t((d) => d.pages.settingsSubjects.displayCurrencyNote)}
        >
            <Field label={t((d) => d.pages.settingsSubjects.displayCurrencyLabel)} htmlFor="display-currency">
                <PanelSelect
                    id="display-currency"
                    value={current}
                    onValueChange={(next) => {
                        if (!saving && next !== '' && next !== current) {
                            void change(next);
                        }
                    }}
                    options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                />
            </Field>
        </Panel>
    );
}
