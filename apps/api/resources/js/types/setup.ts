import { bool, nullableStr, num, obj, str } from '../lib/json';
import { parseFiscalValueDisplay, type FiscalValueDisplay } from './fiscalParameters';

/**
 * The install's readiness, as `GET /api/v1/setup/requirements` answers it (ADR 0027). A package
 * declares the legislation it needs; the endpoint resolves each need and rolls the answers up to
 * whether the install is bootstrapped and which features are available. The screen renders what the
 * endpoint declares — it does not know any one package's requirements by name.
 */

/** Where a `parameters` need stands: approved (met), proposed and awaiting approval, or nothing read yet. */
export type ParametersState = 'approved' | 'proposed' | 'absent';

/**
 * The server-decided detail on a `parameters` need (ADR 0027), so the screen can name what a person
 * is being asked to approve. `year` and `value` are present only in the `proposed` state — the active
 * year and its value, shown the one way the tax-rules board shows it ({@link FiscalValueDisplay}).
 */
export interface ParametersNeedDetail {
    state: ParametersState;
    year: number | null;
    value: FiscalValueDisplay | null;
}

/** The server-decided detail on a `watch` need: whether a run is fetching its acts right now. */
export interface WatchNeedDetail {
    running: boolean;
}

/** One thing a requirement rests on, and whether it is satisfied right now. */
export interface ReadinessNeed {
    /** The kind of thing needed — `law`, `watch`, `parameters`. The screen labels the known kinds. */
    kind: string;
    /** What of that kind — a law identifier, a watch key, `active-year`. Shown as the thing's name. */
    argument: string;
    met: boolean;
    /** Detail on a `parameters` need; null for the other kinds (and when the server sent none). */
    parameters: ParametersNeedDetail | null;
    /** Detail on a `watch` need; null for the other kinds. */
    watch: WatchNeedDetail | null;
}

/** One declared requirement and its needs. */
export interface ReadinessRequirement {
    /** The requirement's identifier, e.g. `tax-romania.cod-fiscal`. */
    key: string;
    /** The package that declared it, e.g. `kern/tax-romania`. */
    declaredBy: string;
    /** `required` — the install needs it; `feature` — one feature rests on it; `optional`. */
    tier: string;
    /** The feature a `feature`-tier requirement gates; null for the others. */
    feature: string | null;
    /** Every need met. */
    met: boolean;
    needs: ReadinessNeed[];
}

/** A feature's availability, rolled up from every requirement that gates it (ANDed). */
export interface ReadinessFeature {
    feature: string;
    available: boolean;
}

/** How the setup screen waits while an import runs — the server's cadence, not the screen's own. */
export interface ReadinessPoll {
    /** How often to re-ask readiness while a step is in flight. */
    everyMs: number;
    /** How long to keep spinning on a step that has not landed before letting go of it. */
    ceilingMs: number;
    /** How long the "setup complete" banner shows before the dashboard opens. */
    redirectMs: number;
}

export interface Readiness {
    /** Every `required`-tier requirement met — the install can compute what it exists to compute. */
    isBootstrapped: boolean;
    requirements: ReadinessRequirement[];
    features: ReadinessFeature[];
    poll: ReadinessPoll;
}

export function parseReadiness(body: string): Readiness {
    const root = obj(JSON.parse(body));
    const requirements = Array.isArray(root.requirements) ? root.requirements : [];
    const features = root.features !== null && typeof root.features === 'object' ? obj(root.features) : {};
    const poll = obj(root.poll);

    return {
        isBootstrapped: bool(root, 'is_bootstrapped'),
        poll: {
            everyMs: num(poll, 'every_ms'),
            ceilingMs: num(poll, 'ceiling_ms'),
            redirectMs: num(poll, 'redirect_ms'),
        },
        requirements: requirements.map((entry): ReadinessRequirement => {
            const requirement = obj(entry);
            const needs = Array.isArray(requirement.needs) ? requirement.needs : [];

            return {
                key: str(requirement, 'key'),
                declaredBy: str(requirement, 'declared_by'),
                tier: str(requirement, 'tier'),
                feature: nullableStr(requirement, 'feature'),
                met: bool(requirement, 'met'),
                needs: needs.map((need): ReadinessNeed => {
                    const row = obj(need);
                    const kind = str(row, 'kind');
                    const detail = row.detail !== null && typeof row.detail === 'object' ? obj(row.detail) : null;

                    return {
                        kind,
                        argument: str(row, 'argument'),
                        met: bool(row, 'met'),
                        parameters: detail !== null && kind === 'parameters' ? parseParametersDetail(detail) : null,
                        watch: detail !== null && kind === 'watch' ? { running: bool(detail, 'running') } : null,
                    };
                }),
            };
        }),
        features: Object.entries(features).map(([feature, value]): ReadinessFeature => ({
            feature,
            available: bool(obj(value), 'available'),
        })),
    };
}

/** A `parameters` need's detail as the server sends it; an unknown state reads as `absent` (nothing to approve yet). */
function parseParametersDetail(detail: Record<string, unknown>): ParametersNeedDetail {
    const state = str(detail, 'state');
    const known: ParametersState = state === 'approved' || state === 'proposed' ? state : 'absent';

    return {
        state: known,
        year: typeof detail.year === 'number' ? detail.year : null,
        value:
            known === 'proposed' && detail.display !== null && typeof detail.display === 'object'
                ? parseFiscalValueDisplay(obj(detail.display))
                : null,
    };
}
