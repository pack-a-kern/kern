import { useEffect, useState } from 'react';
import type { CSSProperties, JSX, ReactNode } from 'react';

/* Token-driven colours for the detective. Applied through `style`/CSS, never as `fill="var(--x)"`
 * presentation attributes — those do not reliably resolve a custom property. The coat, hat, the
 * magnifier frame, the sparks and the glow are all brand mixes, so the character is cocoa on the
 * cocoa palette and follows every other palette; skin, hair, shirt, shoes and the document are
 * illustration tones. */
const C = {
    coat: { fill: 'url(#m-coat)', stroke: 'var(--m-coat-stroke)' } satisfies CSSProperties,
    coatSleeve: { fill: 'var(--m-coat-sleeve)', stroke: 'var(--m-coat-stroke)' } satisfies CSSProperties,
    coatLapel: { fill: 'var(--m-coat-lapel)', stroke: 'var(--m-coat-stroke-2)' } satisfies CSSProperties,
    coatPatch: { fill: 'var(--m-coat-lapel)', stroke: 'var(--m-coat-stroke-2)' } satisfies CSSProperties,
    coatLine: { fill: 'none', stroke: 'var(--m-coat-line)' } satisfies CSSProperties,
    coatBtn: { fill: 'var(--m-coat-btn)' } satisfies CSSProperties,
    hat: { fill: 'url(#m-hat)', stroke: 'var(--m-hat-stroke)' } satisfies CSSProperties,
    hatBrim: { fill: 'var(--m-hat-brim)' } satisfies CSSProperties,
    hatBand: { fill: 'var(--m-hat-band)', stroke: 'var(--m-hat-stroke)' } satisfies CSSProperties,
    hatLine: { fill: 'none', stroke: 'var(--m-hat-line)' } satisfies CSSProperties,
    glassBar: { stroke: 'var(--m-glass-frame)' } satisfies CSSProperties,
    glassRing: { fill: 'var(--m-lens)', stroke: 'var(--m-glass-frame)' } satisfies CSSProperties,
    paper: { fill: 'var(--m-paper)', stroke: 'var(--m-paper-edge)' } satisfies CSSProperties,
    paperLine: { fill: 'none', stroke: 'var(--m-paper-line)' } satisfies CSSProperties,
    paperTag: { fill: 'var(--m-paper-2)' } satisfies CSSProperties,
    paperText: { fill: 'var(--m-paper-text)' } satisfies CSSProperties,
    sparkle: { fill: 'var(--m-accent)' } satisfies CSSProperties,
    lookMark: { fill: 'none', stroke: 'var(--m-accent-soft)' } satisfies CSSProperties,
    lookDot: { fill: 'var(--m-accent-soft)' } satisfies CSSProperties,
    shadow: { fill: 'var(--m-shadow)' } satisfies CSSProperties,
};

function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * The empty state for a year with no assessment yet — a little detective instead of the generic
 * {@link EmptyState} tile. A tax year with nothing in it is a small mystery: no numbers on file, a
 * blank report on the desk. The character idles (breathes, blinks, cycles a line or two), and when
 * you reach for the Assess button it notices — looks over, smiles, raises the magnifier and sparks.
 *
 * Laid out as detective-left / case-file-right from `sm` up (stacked and centred on a phone). The
 * title, line and action reuse `.empty-state*`; the kicker and the larger headline are this layout's
 * own. Under `prefers-reduced-motion` everything rests on a clean idle frame with no motion.
 */
export function TaxMascotEmpty(props: {
    title: string;
    description?: string;
    action?: ReactNode;
    /** The small uppercase label above the title, e.g. "Case file · 2026". */
    kicker?: string;
    /** The lines the detective cycles through while idle. */
    idleLines: string[];
    /** The one line it says when you hover or focus the action. */
    hoverLine: string;
}): JSX.Element {
    const idle = props.idleLines.length > 0 ? props.idleLines : [''];
    const [mode, setMode] = useState<'idle' | 'hover'>('idle');
    const [line, setLine] = useState(0);

    useEffect(() => {
        if (mode !== 'idle' || idle.length < 2 || prefersReducedMotion()) {
            return;
        }
        const id = window.setInterval(() => {
            setLine((i) => (i + 1) % idle.length);
        }, 2800);
        return () => {
            window.clearInterval(id);
        };
    }, [mode, idle.length]);

    const bubble = mode === 'hover' ? props.hoverLine : idle[line % idle.length];

    return (
        <div className="empty-state tax-mascot" data-mode={mode}>
            <div
                className="tax-mascot-stage"
                data-mode={mode}
                // The character notices the action. Hover and focus bubble up from the button inside
                // `.empty-state-action`, so listening here is enough.
                onPointerEnter={() => {
                    setMode('hover');
                }}
                onPointerLeave={() => {
                    setMode('idle');
                }}
                onFocusCapture={() => {
                    setMode('hover');
                }}
                onBlurCapture={() => {
                    setMode('idle');
                }}
            >
                <span className="tax-mascot-ring" aria-hidden="true" />
                <span key={bubble} className="tax-mascot-bubble" role="status" aria-live="polite">
                    {bubble}
                </span>
                <svg viewBox="0 0 420 350" className="tax-mascot-svg" role="img" aria-label={props.title}>
                    <defs>
                        <linearGradient id="m-coat" x1="0" x2="0.85" y1="0" y2="1">
                            <stop style={{ stopColor: 'var(--m-coat-a)' }} />
                            <stop offset="1" style={{ stopColor: 'var(--m-coat-b)' }} />
                        </linearGradient>
                        <linearGradient id="m-hair" x1="0" y1="0" x2="1" y2="1">
                            <stop stopColor="#49352e" />
                            <stop offset="1" stopColor="#2b2526" />
                        </linearGradient>
                        <linearGradient id="m-hat" x1="0" y1="0" x2="0.8" y2="1">
                            <stop style={{ stopColor: 'var(--m-hat-a)' }} />
                            <stop offset="1" style={{ stopColor: 'var(--m-hat-b)' }} />
                        </linearGradient>
                        <filter id="m-blur">
                            <feGaussianBlur stdDeviation="6" />
                        </filter>
                    </defs>

                    {/* quiet setting */}
                    <ellipse cx="191" cy="320" rx="125" ry="13" style={C.shadow} filter="url(#m-blur)" />
                    <ellipse cx="191" cy="317" rx="86" ry="5" style={C.shadow} opacity="0.6" />
                    <path
                        d="M49 298Q71 271 98 292M291 298q27-25 58-2"
                        fill="none"
                        stroke="var(--m-paper-line)"
                        strokeWidth="2"
                        strokeLinecap="round"
                    />

                    {/* the empty report on the desk — the "clue" */}
                    <g className="tax-mascot-report" transform="rotate(7 339 263)">
                        <rect x="298" y="212" width="80" height="97" rx="9" style={C.paper} strokeWidth="2.5" />
                        <path
                            d="M312 231h42m-42 10h34m-34 10h40"
                            style={C.paperLine}
                            strokeWidth="4"
                            strokeLinecap="round"
                        />
                        <rect x="311" y="263" width="53" height="31" rx="6" style={C.paperTag} />
                        <text x="337" y="283" fontWeight="800" fontSize="14" style={C.paperText} textAnchor="middle">
                            2026?
                        </text>
                    </g>

                    <g className="tax-mascot-character">
                        {/* shoes + legs */}
                        <path
                            d="M154 285L148 308Q144 318 154 318H172l8-31M207 287l6 25q2 7 11 7h19q11-2 4-11l-12-27"
                            fill="#46545b"
                            stroke="#354148"
                            strokeWidth="3"
                        />
                        <path
                            d="M143 311q-12 3-10 11 20 8 41 0l-2-10M214 313q-2 12 17 12h22q4-10-13-14"
                            fill="#61443b"
                            stroke="#49352f"
                            strokeWidth="3"
                        />
                        <path d="M138 319q16 6 34 1m45 2q17 5 31-1" fill="none" stroke="#a47f68" strokeWidth="2" />

                        {/* back arm */}
                        <path
                            d="M142 205C126 205 115 222 109 239Q103 259 116 267Q130 272 137 257L154 227"
                            style={C.coatSleeve}
                            strokeWidth="3"
                            strokeLinejoin="round"
                        />
                        <path
                            d="M117 257q-5 12 4 17 12 5 18-9l-6-9"
                            fill="#f3b991"
                            stroke="#986c54"
                            strokeWidth="2.5"
                        />

                        {/* coat torso */}
                        <path
                            d="M152 191Q171 181 191 185Q226 183 241 198L251 235Q261 261 258 298Q219 317 148 303Q136 274 141 241Z"
                            style={C.coat}
                            strokeWidth="3.2"
                            strokeLinejoin="round"
                        />
                        <path d="M148 301q49 18 109-3" style={C.coatLine} strokeWidth="2" opacity="0.8" />

                        {/* shirt + tie */}
                        <path
                            d="M174 190L184 215L196 228L207 211L216 190"
                            fill="#f8f4ec"
                            stroke="#bca99b"
                            strokeWidth="2"
                        />
                        <path d="M186 200l11 14 11-13-7 38-9-1z" fill="#6c8891" />
                        <path d="M191 216l9 0-3 43-9-8z" fill="#56717b" />

                        {/* lapels + buttons */}
                        <path d="M157 191L179 186l12 38-23 21-13-23 12-9z" style={C.coatLapel} strokeWidth="2.5" />
                        <path d="M216 187l20 10-4 18 12 11-19 26-27-28z" style={C.coatLapel} strokeWidth="2.5" />
                        <path d="M197 225l-1 75" style={C.coatLine} strokeWidth="2" />
                        <circle cx="211" cy="258" r="3.5" style={C.coatBtn} />
                        <circle cx="210" cy="283" r="3.5" style={C.coatBtn} />
                        <path d="M149 268q22 6 38 7m17 0q24-5 44-12" style={C.coatLine} strokeWidth="2" />

                        {/* presenting arm + magnifier (pivots at 241,210) */}
                        <g className="tax-mascot-arm">
                            <path
                                d="M234 201Q248 197 259 210L279 233Q289 245 279 255Q270 264 257 251L232 225Q227 213 234 201Z"
                                style={C.coatSleeve}
                                strokeWidth="3"
                                strokeLinejoin="round"
                            />
                            <path d="M270 240l11 11" stroke="#f2d4b4" strokeWidth="5" strokeLinecap="round" />
                            <path
                                d="M274 243q9-5 16 4l10 12q4 8-2 13-8 6-15-2l-11-14z"
                                fill="#f1b68b"
                                stroke="#956447"
                                strokeWidth="2.5"
                            />
                            <g className="tax-mascot-glass">
                                <path d="M289 258L305 224" style={C.glassBar} strokeWidth="9" strokeLinecap="round" />
                                <circle cx="316" cy="201" r="27" style={C.glassRing} strokeWidth="9" />
                                <circle
                                    cx="316"
                                    cy="201"
                                    r="20"
                                    stroke="#fff"
                                    strokeOpacity="0.8"
                                    strokeWidth="2.5"
                                    fill="none"
                                />
                                <path
                                    d="M306 189q7-6 13-5"
                                    stroke="white"
                                    strokeWidth="4"
                                    strokeLinecap="round"
                                    fill="none"
                                />
                            </g>
                        </g>

                        {/* shoulder patch */}
                        <path d="M226 194q14-1 21 12l-13 18q-11-2-17-14" style={C.coatPatch} strokeWidth="2.4" />
                        <path
                            d="M227 200q9 0 13 7"
                            fill="none"
                            stroke="var(--m-coat-lapel)"
                            strokeWidth="3"
                            strokeLinecap="round"
                            opacity="0.7"
                        />

                        {/* neck */}
                        <path d="M172 176v21q7 13 22 13t22-13v-23" fill="#eeb084" stroke="#995f45" strokeWidth="2.5" />

                        <g className="tax-mascot-face">
                            {/* hair behind */}
                            <path
                                d="M121 119Q116 63 168 55Q225 43 249 99L246 160Q238 198 188 203Q139 202 124 165Z"
                                fill="url(#m-hair)"
                                stroke="#382c2a"
                                strokeWidth="2.5"
                            />
                            {/* ears */}
                            <path
                                d="M132 143q-19-15-20 5 0 19 23 24M241 143q20-14 19 6-2 18-21 22"
                                fill="#eeb48b"
                                stroke="#9c6b53"
                                strokeWidth="2.6"
                            />
                            {/* face */}
                            <path
                                d="M128 113Q132 78 181 78Q233 74 245 113L241 164Q234 194 194 201Q153 201 137 178Q129 163 128 113Z"
                                fill="#f3bc93"
                                stroke="#98664c"
                                strokeWidth="2.8"
                            />
                            <path
                                d="M140 155q5 14 15 18m76-19q-4 13-14 18"
                                stroke="#dd9b75"
                                strokeWidth="5"
                                opacity="0.4"
                                fill="none"
                                strokeLinecap="round"
                            />
                            {/* hairline */}
                            <path
                                d="M126 129Q115 74 157 69Q183 54 203 68Q244 66 248 128q-10-19-15-36-14 11-27-1-29 16-56 3-10 22-24 35Z"
                                fill="url(#m-hair)"
                            />
                            <path
                                d="M154 82q18-12 32-7"
                                fill="none"
                                stroke="#7c5e4e"
                                strokeWidth="5"
                                strokeLinecap="round"
                                opacity="0.65"
                            />
                            {/* brows */}
                            <path
                                d="M148 130q14-9 27-1m30 0q12-9 25 1"
                                fill="none"
                                stroke="#55382f"
                                strokeWidth="5.5"
                                strokeLinecap="round"
                            />
                            {/* eyes */}
                            <g className="tax-mascot-eyes">
                                <path
                                    d="M146 143q13-14 30 0-12 14-30 0m56 0q13-14 29 0-11 14-29 0"
                                    fill="#fff9f3"
                                    stroke="#775346"
                                    strokeWidth="2"
                                />
                                <g className="tax-mascot-pupils">
                                    <ellipse cx="163" cy="143" rx="6" ry="7" fill="#353337" />
                                    <ellipse cx="218" cy="143" rx="6" ry="7" fill="#353337" />
                                    <circle cx="165" cy="140" r="2" fill="white" />
                                    <circle cx="220" cy="140" r="2" fill="white" />
                                </g>
                            </g>
                            {/* nose + freckles + cheeks */}
                            <path
                                d="M191 140q-3 15-6 18 7 7 16 1"
                                stroke="#ce8e6f"
                                strokeWidth="2.5"
                                strokeLinecap="round"
                                fill="none"
                            />
                            <circle cx="151" cy="165" r="2" fill="#c88c6d" />
                            <circle cx="158" cy="168" r="1.8" fill="#c88c6d" />
                            <circle cx="221" cy="166" r="2" fill="#c88c6d" />
                            <circle cx="228" cy="163" r="1.8" fill="#c88c6d" />
                            <ellipse cx="147" cy="160" rx="10" ry="6" fill="#d98578" opacity="0.13" />
                            <ellipse cx="233" cy="160" rx="10" ry="6" fill="#d98578" opacity="0.13" />
                            {/* mouths — neutral by default, smile when it notices you */}
                            <path
                                className="tax-mascot-mouth-neutral"
                                d="M178 176q13 6 26 0"
                                stroke="#8d554c"
                                strokeWidth="3.2"
                                strokeLinecap="round"
                                fill="none"
                            />
                            <path
                                className="tax-mascot-mouth-smile"
                                d="M174 171q17 24 36 0-4 16-19 17-13-1-17-17Z"
                                fill="#874b44"
                                stroke="#874b44"
                                strokeWidth="2"
                            />
                            {/* detective hat */}
                            <g className="tax-mascot-hat">
                                <path d="M139 98Q139 50 163 40q19-12 42-1 21 5 29 59Z" style={C.hat} strokeWidth="3" />
                                <path d="M142 82h90l4 20h-98z" style={C.hatBrim} />
                                <path d="M141 94q47 10 95 0" style={C.hatLine} strokeWidth="2.5" />
                                <path
                                    d="M136 99q-40 0-45 13 8 16 95 14 87 2 99-13-6-13-49-15"
                                    style={C.hatBand}
                                    strokeWidth="3"
                                />
                                <path d="M109 112q72 13 146-2" style={C.hatLine} strokeWidth="3" opacity="0.55" />
                            </g>
                        </g>
                    </g>

                    {/* the "looking" curl (idle) and the sparks (when it notices you) */}
                    <g className="tax-mascot-lookmark">
                        <path
                            d="M78 135q11-10 20-1 8 8-3 16-5 3-5 9"
                            style={C.lookMark}
                            strokeWidth="4.5"
                            strokeLinecap="round"
                        />
                        <circle cx="90" cy="170" r="3.5" style={C.lookDot} />
                    </g>
                    <g className="tax-mascot-sparkles" style={C.sparkle}>
                        <path d="M82 141l4 11 11 4-11 4-4 11-4-11-11-4 11-4z" />
                        <path d="M271 96l3 8 8 3-8 3-3 8-3-8-8-3 8-3z" />
                        <circle cx="80" cy="217" r="3.2" />
                    </g>
                </svg>
            </div>
            <div className="tax-mascot-copy">
                {props.kicker !== undefined && <p className="tax-mascot-kicker">{props.kicker}</p>}
                <p className="empty-state-title tax-mascot-title">{props.title}</p>
                {props.description !== undefined && <p className="empty-state-description">{props.description}</p>}
                {props.action !== undefined && <div className="empty-state-action">{props.action}</div>}
            </div>
        </div>
    );
}
