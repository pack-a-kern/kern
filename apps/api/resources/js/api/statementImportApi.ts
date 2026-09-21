import { apiRequest } from '../lib/http';
import {
    parseStatementFormPreview,
    parseStatementImport,
    parseStatementImportPage,
    parseStatementImportDetail,
    type StatementImport,
    type StatementImportDetail,
    type StatementImportPage,
    type StatementForm,
    type StatementFormPreview,
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
): Promise<StatementImport> {
    const body = new FormData();
    body.append('file', file);
    body.append('account_id', accountId);

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

/**
 * What this form would make of the first lines, without committing to anything.
 *
 * Answered by the server on purpose: folding detail lines into the line above, reading a date
 * written in words and picking a label by the direction of the money all happen in one place, and a
 * copy of that here would drift from the one that does the real import — showing rows that are not
 * what gets saved, which is worse than showing nothing.
 */
export async function previewStatementForm(
    subjectId: string,
    importId: string,
    form: StatementForm,
): Promise<StatementFormPreview> {
    return parseStatementFormPreview(
        JSON.parse(
            await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/mapping/preview`, {
                method: 'POST',
                body: { ...form },
            }),
        ) as unknown,
    );
}

/**
 * Confirms how the file is built, and which bank issued it. The server reads the whole file with it
 * and builds the rows.
 */
export async function setStatementImportMapping(
    subjectId: string,
    importId: string,
    form: StatementForm & { source?: string },
    bankId: string | null,
): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/mapping`, {
        method: 'PUT',
        body: { ...form, bank_id: bankId },
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

/**
 * Asks the model again about the rows that still have no category. The rows stay as they are, and a
 * row someone already changed by hand is never sent again. The screen follows the calls over the
 * import's channel as they finish.
 */
export async function requestRowSuggestions(subjectId: string, importId: string): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/suggestions`, { method: 'POST' });
}

/**
 * Reads the stored file again and asks the model again how it is built — the way to repeat a mapping
 * call that failed, since uploading the same file twice is refused. The form already there stays
 * until a new one arrives.
 */
export async function requestColumnSuggestion(subjectId: string, importId: string): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/mapping/suggest`, { method: 'POST' });
}

/** Takes a whole import back out: every transaction it created goes. */
export async function undoStatementImport(subjectId: string, importId: string): Promise<void> {
    await apiRequest(`${base(subjectId)}/${encodeURIComponent(importId)}/undo`, { method: 'POST' });
}
