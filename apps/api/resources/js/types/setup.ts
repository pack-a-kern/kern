import { bool, nullableStr, obj, str } from '../lib/json';

/**
 * The install's readiness, as `GET /api/v1/setup/requirements` answers it (ADR 0027). A package
 * declares the legislation it needs; the endpoint resolves each need and rolls the answers up to
 * whether the install is bootstrapped and which features are available. The screen renders what the
 * endpoint declares — it does not know any one package's requirements by name.
 */

/** One thing a requirement rests on, and whether it is satisfied right now. */
export interface ReadinessNeed {
    /** The kind of thing needed — `law`, `watch`, `parameters`. The screen labels the known kinds. */
    kind: string;
    /** What of that kind — a law identifier, a watch key, `active-year`. Shown as the thing's name. */
    argument: string;
    met: boolean;
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

export interface Readiness {
    /** Every `required`-tier requirement met — the install can compute what it exists to compute. */
    isBootstrapped: boolean;
    requirements: ReadinessRequirement[];
    features: ReadinessFeature[];
}

export function parseReadiness(body: string): Readiness {
    const root = obj(JSON.parse(body));
    const requirements = Array.isArray(root.requirements) ? root.requirements : [];
    const features = root.features !== null && typeof root.features === 'object' ? obj(root.features) : {};

    return {
        isBootstrapped: bool(root, 'is_bootstrapped'),
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

                    return {
                        kind: str(row, 'kind'),
                        argument: str(row, 'argument'),
                        met: bool(row, 'met'),
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
