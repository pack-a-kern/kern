import { describe, expect, it, vi } from 'vitest';

import { ApiError, StepUpCancelled } from '../http';
import { reportApiFailure } from '../apiErrors';
import { ro } from '../../i18n/ro';
import type { Dictionary } from '../../i18n/dictionary';

vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));

const { toast } = await import('sonner');
const error = vi.mocked(toast.error);

/** The real dictionary, so a test cannot pass against a key that no longer exists. */
function t<T>(pick: (d: Dictionary) => T): T {
    return pick(ro);
}

/**
 * One place decides whether a screen says anything about a failed request.
 *
 * It exists because each screen used to decide for itself, and none of them could tell a dismissed
 * step-up dialog from a real refusal — so cancelling "end this session" reported that the session
 * could not be ended.
 */
describe('reportApiFailure', () => {
    it('says nothing when the person dismissed the confirmation', () => {
        error.mockClear();

        reportApiFailure(new StepUpCancelled(), t, 'The session could not be ended');

        expect(error).not.toHaveBeenCalled();
    });

    it('reports a real refusal, with the screen’s own line above the reason', () => {
        error.mockClear();

        reportApiFailure(new ApiError(403, 'Forbidden'), t, 'The session could not be ended');

        expect(error).toHaveBeenCalledWith('The session could not be ended', {
            description: 'Forbidden',
        });
    });

    it('makes the reason the whole message when the screen gives no line', () => {
        error.mockClear();

        reportApiFailure(new ApiError(422, '', { fiscal_year: ['Anul nu este acceptat.'] }), t);

        expect(error).toHaveBeenCalledWith('Anul nu este acceptat.');
    });

    /* A rate limit says *wait*, not *fix something* — and it says it in the reader's language. */
    it('translates a rate limit rather than passing on the server’s English', () => {
        error.mockClear();

        reportApiFailure(new ApiError(429, 'Too Many Attempts.'), t);

        expect(error).toHaveBeenCalledWith(ro.errors.rateLimited);
    });
});
