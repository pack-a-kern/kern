import { getCsrfToken } from './csrf';
import { isRecord } from './validate';

/**
 * Thrown by {@link apiRequest} for any non-2xx response. `errors` mirrors Laravel's standard
 * `ValidationException` JSON envelope (`{"message": ..., "errors": {"field": ["..."]}}`) when the
 * server sent one; every controller in `app/Http/Controllers/Api/V1/**` that fails validation
 * uses this shape (`Illuminate\Validation\ValidationException`'s default renderer).
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors?: Record<string, string[]>,
        public readonly reason?: string,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** True for HTTP 429 — the named rate limiters in `App\Domain\Identity\IdentityServiceProvider`. */
    get isRateLimited(): boolean {
        return this.status === 429;
    }
}

export interface RequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    /** A plain object is sent as `application/json`; a `FormData` is sent as-is (multipart). */
    body?: Record<string, unknown> | FormData;
    signal?: AbortSignal;
}

/** Builds the request `Headers`/body pair shared by {@link apiRequest} and {@link apiRequestBinary}. */
function buildRequestInit(path: string, options: RequestOptions): [string, RequestInit] {
    const method = options.method ?? 'GET';
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    let body: BodyInit | undefined;

    if (options.body instanceof FormData) {
        body = options.body;
    } else if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(options.body);
    }

    if (method !== 'GET') {
        headers['X-CSRF-TOKEN'] = getCsrfToken();
    }

    return [
        `/api${path}`,
        {
            method,
            credentials: 'same-origin',
            headers,
            ...(body !== undefined ? { body } : {}),
            ...(options.signal ? { signal: options.signal } : {}),
        },
    ];
}

async function performFetch(url: string, init: RequestInit): Promise<Response> {
    try {
        return await fetch(url, init);
    } catch {
        // No user-facing copy lives in code (BLUEPRINT.md §19.1): the message here is for logs and
        // stack traces, and `reason` is what the UI translates through `resources/js/i18n`.
        throw new ApiError(0, 'The server could not be reached.', undefined, 'network');
    }
}

/**
 * Reads a non-2xx response's body (best-effort JSON) and throws the corresponding
 * {@link ApiError}. Shared by {@link apiRequest} and {@link apiRequestBinary} so both surfaces
 * report a failed request identically, regardless of what a *successful* response's body is.
 */
async function throwForFailedResponse(response: Response): Promise<never> {
    const text = await response.text();
    let data: unknown = null;

    try {
        data = text === '' ? null : (JSON.parse(text) as unknown);
    } catch {
        // Non-JSON error body (e.g. a proxy's plain-text 502) — fall through to the generic message.
    }

    const message =
        isRecord(data) && typeof data['message'] === 'string'
            ? data['message']
            : // No user-facing copy in code (BLUEPRINT.md §19.1): this is for logs and stack traces.
              // A screen shows its own translated message, keyed off the status or `reason`.
              `Request failed (${String(response.status)}).`;
    const errors =
        isRecord(data) && isRecord(data['errors']) ? (data['errors'] as Record<string, string[]>) : undefined;
    const reason = isRecord(data) && typeof data['reason'] === 'string' ? data['reason'] : undefined;

    throw new ApiError(response.status, message, errors, reason);
}

/**
 * The one place every call to `/api/v1/**` goes through: same-origin session cookie,
 * `X-CSRF-TOKEN` on every mutating verb, and a typed {@link ApiError} instead of a raw
 * `Response`. Resolves to the **raw response text** on success — callers pass it straight into
 * one of `resources/js/types/*.ts`'s `parse*()` functions, which both `JSON.parse` it and
 * validate its shape in one pass (`BLUEPRINT.md` §19.5: runtime validation at every API boundary).
 * This module only handles the HTTP envelope, never the body's shape.
 */
export async function apiRequest(path: string, options: RequestOptions = {}): Promise<string> {
    const [url, init] = buildRequestInit(path, options);
    const response = await performFetch(url, init);

    if (!response.ok) {
        return throwForFailedResponse(response);
    }

    return response.text();
}

/**
 * Same envelope/auth/error handling as {@link apiRequest}, but resolves to a `Blob` instead of
 * text — for endpoints whose successful body is binary, not JSON
 * (`GET .../documents/{document}/content`). A failed response is still parsed as JSON and thrown
 * as the same typed {@link ApiError} `apiRequest` throws, so callers handle both surfaces
 * identically (e.g. a `409 {"reason": "CONTENT_NOT_AVAILABLE"}`).
 */
export async function apiRequestBinary(path: string, options: RequestOptions = {}): Promise<Blob> {
    const [url, init] = buildRequestInit(path, options);
    const response = await performFetch(url, init);

    if (!response.ok) {
        return throwForFailedResponse(response);
    }

    return response.blob();
}
