import { Trash2, Upload } from 'lucide-react';
import { useState, type JSX } from 'react';

import { Avatar, PanelButton } from './panel';
import { useTranslation } from '../i18n';
import { describeApiFailure } from '../lib/apiErrors';
import { ApiError } from '../lib/http';
import type { Dictionary } from '../i18n/dictionary';

/**
 * A picture, with the two controls that change it, for anything that can wear one.
 *
 * Written for a user and now used by a taxpayer as well, which is the whole reason it is here rather
 * than inside the screen that needed it first. The caller supplies the two lines of text, the URL and
 * the two requests; everything else — what a refusal says, when the file input is cleared, which
 * label the button shows — is the same question whoever is wearing the picture.
 *
 * ## Uploaded on choice, not on Save
 *
 * Holding the file until Save would mean the image is validated, scanned and rebuilt during a request
 * the person thinks is saving something else — so a refusal would surface as "save failed", with the
 * other change in an unknown state. Uploading on choice keeps the two outcomes apart.
 *
 * ## The file input is hidden behind a button
 *
 * A bare `<input type="file">` cannot be styled and renders as a grey rectangle the browser owns. The
 * real input is present and focusable — the label wraps it — so a keyboard and a screen reader reach
 * it exactly as they would any other control; only the browser's own chrome is replaced.
 */
export default function AvatarField(props: {
    /** Drawn when there is no picture, and behind one that has not loaded. */
    initials: string;
    /** The two lines beside it: what this is, and a detail under it. */
    name: string;
    sub: string;
    /** The picture's URL, or undefined for initials. The caller builds it, versioned. */
    src?: string | undefined;
    /** Resolves to the new version string, which the caller stores to rebuild `src`. */
    onUpload: (file: File) => Promise<string | null>;
    onRemove: () => Promise<void>;
    /** Told the new version, or null once the picture is gone. */
    onChange: (version: string | null) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const hasPicture = props.src !== undefined;

    async function choose(file: File | undefined): Promise<void> {
        if (file === undefined) {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            props.onChange(await props.onUpload(file));
        } catch (failure: unknown) {
            setError(avatarFailureMessage(failure, t));
        } finally {
            setBusy(false);
        }
    }

    async function clear(): Promise<void> {
        setBusy(true);
        setError(null);

        try {
            await props.onRemove();
            props.onChange(null);
        } catch (failure: unknown) {
            setError(avatarFailureMessage(failure, t));
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="avatar-field">
            <Avatar className="avatar-lg" initials={props.initials} src={props.src} />

            <div className="min-w-0 flex-1">
                <div className="identity-name truncate">{props.name}</div>
                <div className="identity-sub truncate">{props.sub}</div>

                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                    <label className="ctl-action-link" aria-busy={busy}>
                        <Upload className="size-3.5" aria-hidden="true" />
                        {busy
                            ? t((d) => d.avatar.uploading)
                            : hasPicture
                              ? t((d) => d.avatar.replace)
                              : t((d) => d.avatar.choose)}
                        <input
                            type="file"
                            className="sr-only"
                            accept="image/jpeg,image/png,image/webp"
                            disabled={busy}
                            onChange={(event) => {
                                void choose(event.target.files?.[0]);
                                // Cleared so choosing the same file twice fires `change` again, which
                                // it otherwise does not — the value would be unchanged.
                                event.target.value = '';
                            }}
                        />
                    </label>

                    {!hasPicture ? null : (
                        <PanelButton
                            variant="outline"
                            disabled={busy}
                            onClick={() => {
                                void clear();
                            }}
                        >
                            <Trash2 className="size-3.5" aria-hidden="true" />
                            {t((d) => d.avatar.remove)}
                        </PanelButton>
                    )}
                </div>

                {error === null ? null : <p className="note text-destructive mt-1.5">{error}</p>}
            </div>
        </div>
    );
}

/**
 * Why an upload was refused, in the reader's language.
 *
 * The server's `reason` is the half that can be translated — "infected", "not_an_image" — and its
 * `message` is English prose for a log. Anything this does not recognise falls through to the shared
 * translator, which already knows a 429 and a dead network.
 */
export function avatarFailureMessage(failure: unknown, t: <T>(pick: (d: Dictionary) => T) => T): string {
    if (failure instanceof ApiError && failure.reason !== undefined) {
        const known = t((d) => d.avatar.reasons);

        if (failure.reason in known) {
            return known[failure.reason as keyof typeof known];
        }
    }

    return describeApiFailure(failure, t);
}
