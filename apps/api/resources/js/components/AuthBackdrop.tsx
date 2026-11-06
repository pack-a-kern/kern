import type { JSX } from 'react';

// The dial's tick ring: every 5°, with a longer mark every 30°.
const DIAL_TICKS = Array.from({ length: 72 }, (_, i) => {
    const angle = (i * 5 * Math.PI) / 180;
    const inner = i % 6 === 0 ? 362 : 372;
    return {
        x1: 400 + inner * Math.cos(angle),
        y1: 400 + inner * Math.sin(angle),
        x2: 400 + 380 * Math.cos(angle),
        y2: 400 + 380 * Math.sin(angle),
        major: i % 6 === 0,
    };
});

/**
 * Decoration only: a grid, two drifting blooms, a light sweep and a slowly turning dial — the ground
 * the screens before sign-in stand on (the sign-in page, the home page). `compact` is the phone
 * version, behind a header, where a side panel is hidden.
 */
export default function AuthBackdrop({ compact = false }: { compact?: boolean }): JSX.Element {
    return (
        <div className={compact ? 'auth-backdrop auth-backdrop-compact lg:hidden' : 'auth-backdrop'} aria-hidden="true">
            <span className="auth-backdrop-grid" />
            <span className="auth-backdrop-bloom" />
            <span className="auth-backdrop-bloom auth-backdrop-bloom-alt" />
            <span className="auth-backdrop-sweep" />
            <svg className="auth-dial" viewBox="0 0 800 800">
                <g className="auth-dial-turn auth-dial-slow">
                    {DIAL_TICKS.map((tick, i) => (
                        <line key={i} className={tick.major ? 'auth-dial-tick-major' : 'auth-dial-tick'} {...tick} />
                    ))}
                </g>
                <g className="auth-dial-turn">
                    <circle className="auth-dial-ring" cx="400" cy="400" r="330" />
                    <circle className="auth-dial-ring auth-dial-dashed" cx="400" cy="400" r="305" />
                    <circle className="auth-dial-trace" cx="400" cy="400" r="330" pathLength="100" />
                    <circle className="auth-dial-node" cx="400" cy="70" r="4" />
                    <circle className="auth-dial-node" cx="730" cy="400" r="3" />
                </g>
                <g className="auth-dial-turn auth-dial-reverse">
                    <circle className="auth-dial-ring" cx="400" cy="400" r="250" />
                    <circle className="auth-dial-ring auth-dial-dashed" cx="400" cy="400" r="226" />
                    <circle
                        className="auth-dial-trace auth-dial-trace-alt"
                        cx="400"
                        cy="400"
                        r="250"
                        pathLength="100"
                    />
                    <circle className="auth-dial-node" cx="150" cy="400" r="3.5" />
                </g>
                <circle className="auth-dial-ring" cx="400" cy="400" r="150" />
                <circle className="auth-dial-ring auth-dial-dashed" cx="400" cy="400" r="70" />
                <path className="auth-dial-axis" d="M400 20V780M20 400H780M131 131L669 669M669 131L131 669" />
                <circle className="auth-dial-ping" cx="400" cy="400" r="6" />
                <circle className="auth-dial-core" cx="400" cy="400" r="6" />
            </svg>
        </div>
    );
}
