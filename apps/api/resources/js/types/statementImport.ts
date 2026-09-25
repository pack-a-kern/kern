import type { TaxCategory } from './wallet';

/**
 * A bank statement on its way from a file to transactions.
 *
 * The screens follow one resource through four stages — read, match columns, review rows, import —
 * so everything below is one shape with the parts that are filled in at each stage.
 */

/** `App\Domain\StatementImport\Enums\StatementImportStatus`. */
export type StatementImportStatus = 'READING' | 'MAPPING' | 'REVIEW' | 'IMPORTING' | 'IMPORTED' | 'FAILED';

export interface StatementImport {
    id: string;
    accountId: string;
    bankId: string | null;
    /** The bank's own name, where one is known. */
    bankName: string | null;
    documentId: string | null;
    /** CSV, XLSX, XLS or PDF — decided by the bytes, never by the file's name. */
    format: string | null;
    sheet: string | null;
    status: StatementImportStatus;
    /** A closed code the screen translates — NO_TEXT_LAYER, ENCRYPTED_FILE, TOO_LARGE… */
    failureReason: string | null;
    /** Whether the stored file may be read again — open, or failed for a passing reason. */
    canReadAgain: boolean;
    rowCount: number;
    duplicateCount: number;
    excludedCount: number;
    importedCount: number;
    /** Reconciliation: lines read, lines folded into the transaction above, lines skipped. */
    linesRead: number;
    linesFolded: number;
    linesSkipped: number;
    /** Why no form was suggested — UNAVAILABLE, REFUSED, NO_ROOM, UNREADABLE, NOT_THIS_FILE. */
    aiFailureReason: string | null;
    importedAt: string | null;
    createdAt: string;
}

/** One column of the file, described rather than shown: its header and what its values look like. */
export interface StatementColumn {
    index: number;
    header: string;
    /** `date DD.MM.YYYY`, `number -1.234,56`, `text ~46 chars` — what the AI matches columns from. */
    shape: string;
    filledRatio: number;
}

/**
 * The `Label:` prefixes found beneath transactions whose amount sat in one column.
 *
 * Grouped by column because that is what separates money out from money in: in a Romanian export
 * one side carries `Tranzactie la`, the other `Ordonator`, and the screen offers each where it
 * belongs rather than offering all of them everywhere.
 */
export interface StatementLabelGroup {
    amountColumn: number;
    blocks: number;
    labels: { label: string; seenInPercent: number }[];
}

export interface StatementPreview {
    columns: StatementColumn[];
    /** The first rows of the file, as text, for checking a form against. */
    rows: string[][];
    sheets: string[];
    headerRow: number | null;
    warnings: string[];
    labels: StatementLabelGroup[];
    /** What the model replied when asked how this file is built. Structure only — for checking it. */
    aiAnswer: Record<string, unknown> | null;
    /** The counterparty calls, once the rows exist. */
    classification: StatementClassification | null;
    /** How a PDF became this table, when the provider converted it; null when it was read here. */
    conversion: StatementConversion | null;
    /** Where the import's bank came from when it was guessed — its IBAN, or the AI; null otherwise. */
    bankSource: 'IBAN' | 'AI' | null;
}

/** What the converter reported about its own work — never the file's content. */
export interface StatementConversion {
    pipeline: string | null;
    milliseconds: number | null;
    warning: string | null;
}

function parseConversion(value: unknown): StatementConversion | null {
    const row = record(value);

    return row === null
        ? null
        : {
              pipeline: nullableStr(row, 'pipeline'),
              milliseconds: nullableNum(row, 'ms'),
              warning: nullableStr(row, 'warning'),
          };
}

/** One call asking what counterparties are: what was sent, what came back, or why nothing did. */
export interface StatementClassificationBatch {
    sent: Record<string, unknown> | null;
    reply: Record<string, unknown> | null;
    failure: string | null;
}

export interface StatementClassification {
    /** How many calls this round will make — progress is `batches.length` of this. Null while being worked out. */
    expected: number | null;
    /** How many distinct counterparties the calls ask about. Null while being worked out. */
    counterparties: number | null;
    /** Set when no call was made at all, e.g. `UNAVAILABLE`. */
    failure: string | null;
    batches: StatementClassificationBatch[];
}

/** One transaction as the confirmed form would read it — the same builder the import itself uses. */
export interface StatementFormPreviewRow {
    row_number: number;
    booked_at: string | null;
    amount_minor: number | null;
    currency_code: string | null;
    text: string;
    counterparty: string;
    reference: string;
}

export interface StatementFormPreview {
    transactions: StatementFormPreviewRow[];
    linesFolded: number;
    linesSkipped: number;
}

/** `App\Domain\StatementImport\Events\StatementImportChanged` — "something moved, ask again". */
export interface StatementImportEvent {
    importId: string;
    status: StatementImportStatus;
    /** True when the counterparty proposals were written, rather than the status moving. */
    proposals: boolean;
}

/** Where one field lives: a column of the line, a label on a detail line, or a fixed code. */
export interface StatementField {
    type: 'index' | 'label' | 'code';
    value: number | string;
}

/**
 * How the file is built: where every field lives, and what is not a transaction at all.
 *
 * Sent to the server exactly as it is written here, so the keys are the server's rather than
 * camel-cased — this is a form, not a view model.
 */
export interface StatementForm {
    /** One transaction per line, or a line followed by its own detail lines. */
    layout: 'row' | 'block';
    date: { type: 'index'; value: number; format: string };
    currency: StatementField | null;
    amounts: {
        /** `signed` — one column with a sign — or `debit_credit`, two unsigned columns. */
        mode: 'signed' | 'debit_credit';
        amount: StatementField | null;
        debit: StatementField | null;
        credit: StatementField | null;
        decimal_separator: string;
        thousands_separator: string;
    };
    description: StatementField | null;
    /**
     * Answered per direction, and each side may name several places to look.
     *
     * A statement is not written by one machine: a card payment names its payee in a column and a
     * transfer names it on a detail line. The places are tried in order, and the first with anything
     * in it wins — see `statement_import.multi_source_fields` for which fields are allowed this.
     */
    counterparty: { debit: StatementField[]; credit: StatementField[] };
    reference: { debit: StatementField[]; credit: StatementField[] };
    non_transaction_lines: string[];
    identity_labels: string[];
}

/** One statement line, waiting for a person to decide about it. */
export interface StatementRow {
    id: string;
    rowNumber: number;
    bookedAt: string | null;
    amountMinor: number | null;
    currencyCode: string | null;
    description: string;
    counterparty: string | null;
    reference: string | null;
    /** The transaction already in TaxOS this row matches — it arrives unticked. */
    duplicateTransactionId: string | null;
    categoryCode: string | null;
    countryCode: string | null;
    tags: string[];
    /** `RULE` (remembered), `AI`, or `PERSON` once someone has answered it. */
    proposalSource: string | null;
    included: boolean;
    transactionId: string | null;
}

export interface StatementImportDetail {
    /** Which fields of the form may name several places, and how many each may name. */
    multiSource: { fields: string[]; max: number };
    import: StatementImport;
    preview: StatementPreview;
    mapping: StatementForm | null;
    /** How many rows each side has answered so far. */
    proposals: { ai: number; rule: number };
    rows: StatementRow[];
    total: number;
    page: number;
    perPage: number;
    categories: TaxCategory[];
}

export interface StatementImportPage {
    imports: StatementImport[];
    total: number;
    page: number;
    perPage: number;
}

function obj(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
}

function str(row: Record<string, unknown>, key: string): string {
    return typeof row[key] === 'string' ? row[key] : '';
}

function nullableStr(row: Record<string, unknown>, key: string): string | null {
    return typeof row[key] === 'string' ? row[key] : null;
}

function num(row: Record<string, unknown>, key: string): number {
    return typeof row[key] === 'number' ? row[key] : 0;
}

function nullableNum(row: Record<string, unknown>, key: string): number | null {
    return typeof row[key] === 'number' ? row[key] : null;
}

const STATUSES: StatementImportStatus[] = ['READING', 'MAPPING', 'REVIEW', 'IMPORTING', 'IMPORTED', 'FAILED'];

function parseImport(payload: unknown): StatementImport {
    const row = obj(payload);
    const status = str(row, 'status');

    return {
        id: str(row, 'id'),
        accountId: str(row, 'account_id'),
        bankId: nullableStr(row, 'bank_id'),
        bankName: nullableStr(row, 'bank_name'),
        documentId: nullableStr(row, 'document_id'),
        format: nullableStr(row, 'format'),
        sheet: nullableStr(row, 'sheet'),
        // An unknown state reads as failed rather than as one of the working ones: a screen that
        // cannot say what is happening must not claim everything is fine.
        status: STATUSES.includes(status as StatementImportStatus) ? (status as StatementImportStatus) : 'FAILED',
        failureReason: nullableStr(row, 'failure_reason'),
        canReadAgain: row.can_read_again === true,
        rowCount: num(row, 'row_count'),
        duplicateCount: num(row, 'duplicate_count'),
        excludedCount: num(row, 'excluded_count'),
        importedCount: num(row, 'imported_count'),
        linesRead: num(row, 'lines_read'),
        linesFolded: num(row, 'lines_folded'),
        linesSkipped: num(row, 'lines_skipped'),
        aiFailureReason: nullableStr(row, 'ai_failure_reason'),
        importedAt: nullableStr(row, 'imported_at'),
        createdAt: str(row, 'created_at'),
    };
}

export function parseStatementImport(payload: unknown): StatementImport {
    return parseImport(payload);
}

export function parseStatementImportPage(payload: unknown): StatementImportPage {
    const body = obj(payload);

    return {
        imports: (Array.isArray(body.imports) ? body.imports : []).map(parseImport),
        total: num(body, 'total'),
        page: num(body, 'page'),
        perPage: num(body, 'per_page'),
    };
}

function parseRow(payload: unknown): StatementRow {
    const row = obj(payload);

    return {
        id: str(row, 'id'),
        rowNumber: num(row, 'row_number'),
        bookedAt: nullableStr(row, 'booked_at'),
        amountMinor: nullableNum(row, 'amount_minor'),
        currencyCode: nullableStr(row, 'currency_code'),
        description: str(row, 'description'),
        counterparty: nullableStr(row, 'counterparty'),
        reference: nullableStr(row, 'reference'),
        duplicateTransactionId: nullableStr(row, 'duplicate_transaction_id'),
        categoryCode: nullableStr(row, 'category_code'),
        countryCode: nullableStr(row, 'country_code'),
        tags: (Array.isArray(row.tags) ? row.tags : []).filter((tag): tag is string => typeof tag === 'string'),
        proposalSource: nullableStr(row, 'proposal_source'),
        included: row.included === true,
        transactionId: nullableStr(row, 'transaction_id'),
    };
}

function parsePreview(payload: unknown): StatementPreview {
    const preview = obj(payload);

    return {
        columns: (Array.isArray(preview.columns) ? preview.columns : []).map((entry): StatementColumn => {
            const column = obj(entry);

            return {
                index: num(column, 'index'),
                header: str(column, 'header'),
                shape: str(column, 'shape'),
                filledRatio: num(column, 'filled_ratio'),
            };
        }),
        rows: (Array.isArray(preview.rows) ? preview.rows : []).map((entry) =>
            (Array.isArray(entry) ? entry : []).map((cell) => (typeof cell === 'string' ? cell : '')),
        ),
        sheets: (Array.isArray(preview.sheets) ? preview.sheets : []).filter(
            (sheet): sheet is string => typeof sheet === 'string',
        ),
        headerRow: nullableNum(preview, 'header_row'),
        aiAnswer: record(preview.ai_answer),
        classification: parseClassification(preview.classification),
        conversion: parseConversion(preview.conversion),
        bankSource: preview.bank_source === 'IBAN' || preview.bank_source === 'AI' ? preview.bank_source : null,
        warnings: (Array.isArray(preview.warnings) ? preview.warnings : []).filter(
            (warning): warning is string => typeof warning === 'string',
        ),
        labels: (Array.isArray(preview.labels) ? preview.labels : []).map((entry): StatementLabelGroup => {
            const group = obj(entry);

            return {
                amountColumn: num(group, 'amount_column'),
                blocks: num(group, 'blocks'),
                labels: (Array.isArray(group.labels) ? group.labels : []).map((item) => {
                    const label = obj(item);

                    return { label: str(label, 'label'), seenInPercent: num(label, 'seen_in_percent') };
                }),
            };
        }),
    };
}

/** One field as the server wrote it, or null where the file has nothing to read it from. */
function parseField(payload: unknown): StatementField | null {
    if (payload === null || typeof payload !== 'object') {
        return null;
    }

    const field = obj(payload);
    const type = str(field, 'type');
    const value = field.value;

    if (type !== 'index' && type !== 'label' && type !== 'code') {
        return null;
    }

    if (typeof value !== 'number' && typeof value !== 'string') {
        return null;
    }

    return { type, value };
}

function parseStrings(value: unknown): string[] {
    return (Array.isArray(value) ? value : []).filter((entry): entry is string => typeof entry === 'string');
}

/**
 * A field's places, in the order they are tried.
 *
 * Accepts either shape: one place as an object, which is how every mapping saved so far is written
 * and how the model proposes one, or a list of them.
 */
/** Which fields may name several places, and how many — decided by the server, never mirrored here. */
function parseMultiSource(payload: unknown): { fields: string[]; max: number } {
    const row = obj(payload);
    const fields = Array.isArray(row.fields) ? row.fields : [];

    return {
        fields: fields.filter((field): field is string => typeof field === 'string'),
        max: num(row, 'max'),
    };
}

function parseChain(payload: unknown): StatementField[] {
    const places = Array.isArray(payload) ? payload : [payload];

    return places.map(parseField).filter((place): place is StatementField => place !== null);
}

function parseForm(payload: unknown): StatementForm | null {
    if (payload === null || typeof payload !== 'object') {
        return null;
    }

    const row = obj(payload);
    const date = obj(row.date);
    const amounts = obj(row.amounts);
    const counterparty = obj(row.counterparty);
    const reference = obj(row.reference);
    const mode = str(amounts, 'mode');

    return {
        layout: str(row, 'layout') === 'block' ? 'block' : 'row',
        date: { type: 'index', value: num(date, 'value'), format: str(date, 'format') },
        currency: parseField(row.currency),
        amounts: {
            mode: mode === 'debit_credit' ? 'debit_credit' : 'signed',
            amount: parseField(amounts.amount),
            debit: parseField(amounts.debit),
            credit: parseField(amounts.credit),
            decimal_separator: str(amounts, 'decimal_separator'),
            thousands_separator: str(amounts, 'thousands_separator'),
        },
        description: parseField(row.description),
        // One place is stored as an object and several as a list, and both are read back: a mapping
        // the server holds and this parser drops is a choice that vanishes on refresh.
        counterparty: { debit: parseChain(counterparty.debit), credit: parseChain(counterparty.credit) },
        reference: { debit: parseChain(reference.debit), credit: parseChain(reference.credit) },
        non_transaction_lines: parseStrings(row.non_transaction_lines),
        identity_labels: parseStrings(row.identity_labels),
    };
}

/** A JSON object as the server sent it, or null — shown for checking, never interpreted. */
function record(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

function parseClassification(payload: unknown): StatementClassification | null {
    const body = record(payload);

    if (body === null) {
        return null;
    }

    return {
        expected: nullableNum(body, 'expected'),
        counterparties: nullableNum(body, 'counterparties'),
        failure: nullableStr(body, 'failure'),
        batches: (Array.isArray(body.batches) ? body.batches : []).map((entry): StatementClassificationBatch => {
            const batch = obj(entry);

            return { sent: record(batch.sent), reply: record(batch.reply), failure: nullableStr(batch, 'failure') };
        }),
    };
}

export function parseStatementImportEvent(raw: unknown): StatementImportEvent {
    const row = obj(raw);
    const status = str(row, 'status');

    return {
        importId: str(row, 'import_id'),
        status: STATUSES.includes(status as StatementImportStatus) ? (status as StatementImportStatus) : 'FAILED',
        proposals: row.proposals === true,
    };
}

export function parseStatementFormPreview(payload: unknown): StatementFormPreview {
    const body = obj(payload);

    return {
        transactions: (Array.isArray(body.transactions) ? body.transactions : []).map(
            (entry): StatementFormPreviewRow => {
                const row = obj(entry);

                return {
                    row_number: num(row, 'row_number'),
                    booked_at: nullableStr(row, 'booked_at'),
                    amount_minor: nullableNum(row, 'amount_minor'),
                    currency_code: nullableStr(row, 'currency_code'),
                    text: str(row, 'text'),
                    counterparty: str(row, 'counterparty'),
                    reference: str(row, 'reference'),
                };
            },
        ),
        linesFolded: num(body, 'lines_folded'),
        linesSkipped: num(body, 'lines_skipped'),
    };
}

export function parseStatementImportDetail(payload: unknown): StatementImportDetail {
    const body = obj(payload);

    return {
        import: parseImport(body.import),
        preview: parsePreview(body.preview),
        mapping: parseForm(body.mapping),
        multiSource: parseMultiSource(body.multi_source),
        proposals: { ai: num(obj(body.proposals), 'ai'), rule: num(obj(body.proposals), 'rule') },
        rows: (Array.isArray(body.rows) ? body.rows : []).map(parseRow),
        total: num(body, 'total'),
        page: num(body, 'page'),
        perPage: num(body, 'per_page'),
        categories: (Array.isArray(body.categories) ? body.categories : []).map((entry): TaxCategory => {
            const category = obj(entry);
            const direction = str(category, 'direction');

            return {
                code: str(category, 'code'),
                income: category.income === true,
                direction: direction === 'in' || direction === 'out' || direction === 'either' ? direction : 'in',
                preselected: category.preselected === true,
            };
        }),
    };
}
