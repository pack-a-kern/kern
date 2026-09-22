import { apiRequest } from '../lib/http';
import { type FiscalParameterBoard, parseFiscalParameterBoard } from '../types/fiscalParameters';
import { runOperationTask } from './operationsApi';

/** `GET /api/v1/fiscal-parameters` — every value, proposed or decided, with where it was read and where the law says it. */
export async function getFiscalParameterBoard(): Promise<FiscalParameterBoard> {
    return parseFiscalParameterBoard(JSON.parse(await apiRequest('/v1/fiscal-parameters')));
}

/** Reads the minimum-wage decisions and the D212 files again — the `fiscal.parameters.read` task. */
export async function readFiscalParameters(): Promise<void> {
    await runOperationTask('fiscal.parameters.read');
}

/** Approves a proposed value on the paragraph of the law chosen among those found. */
export async function approveFiscalParameter(versionId: string, sectionId: string): Promise<void> {
    await apiRequest(`/v1/fiscal-parameters/versions/${encodeURIComponent(versionId)}/approve`, {
        method: 'POST',
        body: { section_id: sectionId },
    });
}

export async function rejectFiscalParameter(versionId: string): Promise<void> {
    await apiRequest(`/v1/fiscal-parameters/versions/${encodeURIComponent(versionId)}/reject`, { method: 'POST' });
}

/** Proposes a year from the one before: every value approved for it that the new year's law still says. */
export async function startFiscalYear(year: number): Promise<number> {
    const body = JSON.parse(await apiRequest('/v1/fiscal-parameters/years', { method: 'POST', body: { year } })) as {
        proposed?: unknown;
    };

    return typeof body.proposed === 'number' ? body.proposed : 0;
}
