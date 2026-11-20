import { apiRequest } from '../lib/http';
import { bool, num, obj, str } from '../lib/json';
import { type FiscalParameterBoard, parseFiscalParameterBoard } from '../types/fiscalParameters';

/** How the engine's rule classes (the formulas) compare with the `tax_rule_versions` rows registered for them. */
export interface TaxRuleSyncStatus {
    /** Rule classes the engine's code defines. */
    definedInCode: number;
    /** How many of those already have a registered version. */
    registered: number;
    /** The ones with no row yet — Import records a candidate for each. */
    missing: { code: string; version: string }[];
    inSync: boolean;
}

function parseTaxRuleSync(payload: unknown): TaxRuleSyncStatus {
    const body = obj(payload);
    const missing = Array.isArray(body.missing) ? body.missing : [];

    return {
        definedInCode: num(body, 'definedInCode'),
        registered: num(body, 'registered'),
        missing: missing.map((entry) => {
            const row = obj(entry);

            return { code: str(row, 'code'), version: str(row, 'version') };
        }),
        inSync: bool(body, 'inSync'),
    };
}

/** `GET /api/v1/fiscal-parameters/rules/sync` — rule classes defined in code vs recorded in the database. */
export async function getTaxRuleSync(): Promise<TaxRuleSyncStatus> {
    return parseTaxRuleSync(JSON.parse(await apiRequest('/v1/fiscal-parameters/rules/sync')));
}

/** Records a candidate version for every rule the database is missing; returns the new figures and how many it added. */
export async function importTaxRules(): Promise<TaxRuleSyncStatus & { recorded: number }> {
    const payload: unknown = JSON.parse(await apiRequest('/v1/fiscal-parameters/rules/import', { method: 'POST' }));

    return { ...parseTaxRuleSync(payload), recorded: num(obj(payload), 'recorded') };
}

/** `GET /api/v1/fiscal-parameters` — every value, proposed or decided, with where it was read and where the law says it. */
export async function getFiscalParameterBoard(): Promise<FiscalParameterBoard> {
    return parseFiscalParameterBoard(JSON.parse(await apiRequest('/v1/fiscal-parameters')));
}

/** Approves a proposed value on the paragraph of the law it was read in. */
export async function approveFiscalParameter(versionId: string, sectionId: string): Promise<void> {
    await apiRequest(`/v1/fiscal-parameters/versions/${encodeURIComponent(versionId)}/approve`, {
        method: 'POST',
        body: { section_id: sectionId },
    });
}

export async function rejectFiscalParameter(versionId: string): Promise<void> {
    await apiRequest(`/v1/fiscal-parameters/versions/${encodeURIComponent(versionId)}/reject`, { method: 'POST' });
}

/** Drops every value read, proposed or decided, so the law can be read again from nothing. */
export async function dropFiscalParameterValues(): Promise<void> {
    await apiRequest('/v1/fiscal-parameters/values', { method: 'DELETE' });
}

/** Asks for one year's law to be read. Queued: the values appear as they are read. */
export async function readFiscalYear(year: number): Promise<void> {
    await apiRequest('/v1/fiscal-parameters/years', { method: 'POST', body: { year } });
}
