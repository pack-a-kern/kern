import { apiRequest } from '../lib/http';
import { type FiscalParameterBoard, parseFiscalParameterBoard } from '../types/fiscalParameters';

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

/** Asks for one year's law to be read. Queued: the values appear as they are read. */
export async function readFiscalYear(year: number): Promise<void> {
    await apiRequest('/v1/fiscal-parameters/years', { method: 'POST', body: { year } });
}
