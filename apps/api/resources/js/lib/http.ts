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

    /** True for HTTP 429 — the named rate limiters in `Kern\Platform\Identity\IdentityServiceProvider`. */
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
    return throwForFailure(response.status, await response.text());
}

/**
 * The status/body half of {@link throwForFailedResponse}, split out so the `XMLHttpRequest` upload
 * path reports a failure identically to every `fetch` call — a 422 rejection or a 409 duplicate
 * must not mean something different just because the request needed progress events.
 */
function throwForFailure(status: number, text: string): never {
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
              `Request failed (${String(status)}).`;
    const errors =
        isRecord(data) && isRecord(data['errors']) ? (data['errors'] as Record<string, string[]>) : undefined;
    const reason = isRecord(data) && typeof data['reason'] === 'string' ? data['reason'] : undefined;

    throw new ApiError(status, message, errors, reason);
}

/**
 * Asks the person to prove their second factor, resolving true once they have.
 *
 * Registered by `<StepUpGate>` at the root of the app, which owns the dialog. This module cannot open
 * one itself — it is not a component and has no idea what is on screen — and a React context cannot
 * be read from a plain async function, so the two meet here.
 */
type StepUpPrompt = () => Promise<boolean>;

let promptForStepUp: StepUpPrompt | null = null;

/** Called once, by `<StepUpGate>`. A second registration replaces the first. */
export function setStepUpPrompt(prompt: StepUpPrompt | null): void {
    promptForStepUp = prompt;
}

/**
 * Whether this failure is the server asking for a code rather than refusing outright.
 *
 * `MFA_ENROLLMENT_REQUIRED` is deliberately **not** included: that account has nothing to prove with,
 * so a dialog would be unanswerable and the screen must show the refusal instead.
 */
function needsStepUp(error: unknown): boolean {
    return error instanceof ApiError && error.status === 403 && error.reason === 'VERIFICATION_REQUIRED';
}

/**
 * Runs a request, and if the server asks for recent verification, asks the person and runs it once more.
 *
 * **Exactly once.** A retry loop around a non-idempotent POST is how one click becomes two
 * registrations, so a second `VERIFICATION_REQUIRED` is thrown rather than prompted again — that is a
 * server-side disagreement, not something the person can fix by typing another code.
 *
 * With no prompt registered, or when the dialog is dismissed, the original error is rethrown and the
 * calling screen reports it as it would any other failure.
 */
async function withStepUp<T>(attempt: () => Promise<T>): Promise<T> {
    try {
        return await attempt();
    } catch (cause: unknown) {
        if (!needsStepUp(cause) || promptForStepUp === null) {
            throw cause;
        }

        const verified = await promptForStepUp();

        if (!verified) {
            throw cause;
        }

        return attempt();
    }
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
    return withStepUp(async () => {
        // Rebuilt per attempt: a retry after the dialog needs a fresh CSRF token, because verifying
        // wrote to the session and the token may have rotated.
        const [url, init] = buildRequestInit(path, options);
        const response = await performFetch(url, init);

        if (!response.ok) {
            return throwForFailedResponse(response);
        }

        return response.text();
    });
}

/**
 * Same envelope/auth/error handling as {@link apiRequest}, but resolves to a `Blob` instead of
 * text — for endpoints whose successful body is binary, not JSON
 * (`GET .../documents/{document}/content`). A failed response is still parsed as JSON and thrown
 * as the same typed {@link ApiError} `apiRequest` throws, so callers handle both surfaces
 * identically (e.g. a `409 {"reason": "CONTENT_NOT_AVAILABLE"}`).
 */
export async function apiRequestBinary(path: string, options: RequestOptions = {}): Promise<Blob> {
    return withStepUp(async () => {
        const [url, init] = buildRequestInit(path, options);
        const response = await performFetch(url, init);

        if (!response.ok) {
            return throwForFailedResponse(response);
        }

        return response.blob();
    });
}

/**
 * A multipart upload that reports how much of the body has gone out.
 *
 * `fetch` cannot do this — it has no upload-progress event — so this is the one request in the app
 * built on `XMLHttpRequest`. Everything else about the envelope is identical to {@link apiRequest}:
 * same URL prefix, same credentials, same CSRF header, same {@link ApiError} on failure, because
 * they come from the same {@link buildRequestInit} and {@link throwForFailure}.
 *
 * `onProgress` receives a fraction in `[0, 1]`, and only while the length is computable — a request
 * whose total size the browser cannot determine reports nothing rather than inventing a number.
 * It fires for the *upload*, so reaching 1 means the bytes are sent, not that the server has
 * answered; the caller still awaits the response.
 */
export async function apiUpload(
    path: string,
    body: FormData,
    onProgress?: (fraction: number) => void,
    signal?: AbortSignal,
): Promise<string> {
    const [url, init] = buildRequestInit(path, { method: 'POST', body });
    const headers = init.headers as Record<string, string>;

    return new Promise<string>((resolve, reject) => {
        const request = new XMLHttpRequest();

        request.open('POST', url, true);
        request.withCredentials = true;

        for (const [name, value] of Object.entries(headers)) {
            request.setRequestHeader(name, value);
        }

        if (onProgress) {
            request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable && event.total > 0) {
                    onProgress(event.loaded / event.total);
                }
            });
        }

        request.addEventListener('load', () => {
            if (request.status >= 200 && request.status < 300) {
                resolve(request.responseText);

                return;
            }

            try {
                throwForFailure(request.status, request.responseText);
            } catch (error) {
                reject(error instanceof Error ? error : new Error(String(error)));
            }
        });

        // Same developer-facing string `performFetch` throws, so a dropped connection is one
        // condition with one `reason` for the UI to translate, however the request was made.
        request.addEventListener('error', () => {
            reject(new ApiError(0, 'The server could not be reached.', undefined, 'network'));
        });
        request.addEventListener('abort', () => {
            reject(new ApiError(0, 'The upload was aborted.', undefined, 'network'));
        });

        if (signal) {
            signal.addEventListener('abort', () => {
                request.abort();
            });
        }

        request.send(body);
    });
}
