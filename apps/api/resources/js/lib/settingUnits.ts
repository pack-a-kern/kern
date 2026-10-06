/**
 * Settings are stored in the unit the server uses — bytes, seconds, milliseconds — because that is
 * what the code consuming them expects. People do not read bytes, so each such setting is shown in
 * a sensible unit and converted back on save.
 *
 * The conversion is deliberately lossless in the direction that matters: what you type is what gets
 * stored, exactly, with no rounding drift between display and value.
 */
export interface DisplayUnit {
    /** Shown beside the input, e.g. "MB". */
    suffix: string;
    /** Stored value → displayed number. */
    toDisplay: (stored: string) => string;
    /** Displayed number → stored value. */
    toStored: (display: string) => string;
    step?: string;
}

const MEGABYTE = 1024 * 1024;

const bytesAsMegabytes: DisplayUnit = {
    suffix: 'MB',
    toDisplay: (stored) => {
        const bytes = Number(stored);
        if (!Number.isFinite(bytes)) {
            return stored;
        }
        // Trim trailing zeroes so 10485760 reads as "10", not "10.00".
        return String(Number((bytes / MEGABYTE).toFixed(2)));
    },
    toStored: (display) => {
        const megabytes = Number(display);
        return Number.isFinite(megabytes) ? String(Math.round(megabytes * MEGABYTE)) : display;
    },
    step: '0.5',
};

const megapixels: DisplayUnit = {
    suffix: 'MP',
    toDisplay: (stored) => {
        const pixels = Number(stored);
        return Number.isFinite(pixels) ? String(Number((pixels / 1_000_000).toFixed(2))) : stored;
    },
    toStored: (display) => {
        const mp = Number(display);
        return Number.isFinite(mp) ? String(Math.round(mp * 1_000_000)) : display;
    },
    step: '0.5',
};

const identity = (suffix: string): DisplayUnit => ({
    suffix,
    toDisplay: (stored) => stored,
    toStored: (display) => display,
});

const UNITS: Record<string, DisplayUnit> = {
    'documents.max_pdf_bytes': bytesAsMegabytes,
    'documents.max_image_bytes': bytesAsMegabytes,
    'sources.fetch_max_bytes': bytesAsMegabytes,
    'documents.max_image_pixels': megapixels,
    'documents.upload_rate_limit_decay_seconds': identity('s'),
    'extraction.timeout_seconds': identity('s'),
    'sources.fetch_timeout_seconds': identity('s'),
    'spv.challenge_ttl_seconds': identity('s'),
    'browser_worker.timeout_seconds': identity('s'),
    'security.webauthn_challenge_ttl_seconds': identity('s'),
    'security.webauthn_timeout_ms': identity('ms'),
    'security.session_lifetime_minutes': identity('min'),
    'spv.messages_window_days': identity('d'),
};

export function unitFor(key: string): DisplayUnit | null {
    return UNITS[key] ?? null;
}
