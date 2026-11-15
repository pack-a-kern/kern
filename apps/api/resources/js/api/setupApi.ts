import { apiRequest } from '../lib/http';
import { parseReadiness, type Readiness } from '../types/setup';

/**
 * `GET /api/v1/setup/requirements` — what each package declared it needs, resolved to whether the
 * install is bootstrapped and each feature available (ADR 0027). Install state, not a taxpayer's,
 * so it takes no subject.
 */
export async function getReadiness(): Promise<Readiness> {
    return parseReadiness(await apiRequest('/v1/setup/requirements'));
}
