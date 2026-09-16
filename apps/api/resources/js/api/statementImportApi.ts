import { apiRequest } from '../lib/http';
import {
    parseStatementImport,
    parseStatementImportPage,
    parseStatementImportDetail,
    type StatementImport,
    type StatementImportDetail,
    type StatementImportPage,
    type StatementForm,
} from '../types/statementImport';

/**
 * Importing a bank's own statement (`docs/handoff/045-statement-import.md`).
 *
 * The flow is a resource with a state rather than one request: the file is uploaded, read in the
 * background, its columns confirmed, its rows reviewed, and only then written. Every function here
 * is one step of that, and `getStatementImport` is what the screen polls between them.
 */

function base(subjectId: string): string {
    return `/v1/subjects/${encodeURIComponent(subjectId)}/wallet/imports`;
}

/** Uploads the file and starts an import. Resolves as soon as it is queued, not when it is read. */
export async function startStatementImport(
    subjectId: string,
    file: File,
    accountId: string,
    bankId: string | null,
): Promise<StatementImport> {
    const body = new FormData();
    body.append('file', file);
    body.append('account_id', accountId);

    if (bankId !== null && bankId !== '') {
        body.append('bank_id', bankId);
    }

    return parseStatementImport(JSON.parse(await apiRequest(base(subjectId), { method: 'POST', body })));
}

export async function listStatementImports(subjectId: string, page = 1): Promise<StatementImportPage> {
    return parseStatementImportPage(JSON.parse(await apiRequest(`${base(subjectId)}?page=${String(page)}`)) as unknown);
}

/**
 * One import at whatever stage it has reached: the preview while columns are being matched, the rows
 * once they are, the counts after it has run.
 */
export async function getStatementImport(
    subjectId: string,
    importId: string,
    options: { filter?: string; page?: number; perPage?: number } = {},
): Promise<StatementImportDetail> {
    const params = new URLSearchParams();

    if (options.filter !== undefined && options.filter !== '') {
        params.set('filter', options.filter);
    }

    if (options.page !== undefined) {
        params.set('page', String(options.page));
    }

    if (options.perPage !== undefined) {
        params.set('per_page', String(options.perPage));
    }

    const query = params.toString();

    return parseStatementImportDetail(
        JSON.parse(
            await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}${query === '' ? '' : `?${query}`}`),
        ) as unknown,
    );
}

/** Confirms how the file is built. The server reads the whole file with it and builds the rows. */
export async function setStatementImportMapping(
    subjectId: string,
    importId: string,
    form: StatementForm & { source?: string },
): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/mapping`, {
        method: 'PUT',
        body: { ...form },
    });
}

/**
 * What a person decides about a set of rows. Only the keys sent are changed, so tagging a selection
 * does not untick it.
 */
export async function updateStatementImportRows(
    subjectId: string,
    importId: string,
    rowIds: string[],
    changes: { included?: boolean; category_code?: string | null; country_code?: string | null; tags?: string[] },
): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/rows`, {
        method: 'PATCH',
        body: { row_ids: rowIds, ...changes },
    });
}

/** Writes the ticked rows into the ledger. A job does the writing; the screen follows the status. */
export async function commitStatementImport(subjectId: string, importId: string): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/commit`, { method: 'POST' });
}

/**
 * Removes an import that never became transactions — a wrong account, a file read twice.
 *
 * Refused for one that did: those are undone first, so the ledger never holds figures pointing at
 * an import nobody can look up. The statement file itself stays in the documents list.
 */
export async function deleteStatementImport(subjectId: string, importId: string): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}`, { method: 'DELETE' });
}

/** Takes a whole import back out: every transaction it created goes. */
export async function undoStatementImport(subjectId: string, importId: string): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/undo`, { method: 'POST' });
}
