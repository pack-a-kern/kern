import { apiRequest } from '../lib/http';
import { parseJson, vNumber, vObject, vString } from '../lib/validate';
import {
    type LoginResult,
    type MfaStatus,
    type SessionSummary,
    type TotpEnrollment,
    type WebauthnOptionsJson,
    parseLoginResult,
    parseMfaStatus,
    parseRecoveryCodes,
    parseSessionList,
    parseTotpEnrollment,
    parseWebauthnOptions,
} from '../types/auth';

const parseId = (text: string): { id: string } => parseJson(text, vObject({ id: vString }));

export async function register(email: string, password: string): Promise<{ id: string }> {
    const text = await apiRequest('/v1/auth/register', { method: 'POST', body: { email, password } });

    return parseId(text);
}

export async function login(email: string, password: string): Promise<LoginResult> {
    const text = await apiRequest('/v1/auth/login', { method: 'POST', body: { email, password } });

    return parseLoginResult(text);
}

export async function completeMfaChallenge(code: string): Promise<{ id: string }> {
    const text = await apiRequest('/v1/auth/mfa/challenge', { method: 'POST', body: { code } });

    return parseId(text);
}

export async function requestPasskeyLoginOptions(email: string): Promise<WebauthnOptionsJson> {
    const text = await apiRequest('/v1/auth/passkeys/login/options', { method: 'POST', body: { email } });

    return parseWebauthnOptions(text);
}

export async function completePasskeyLogin(
    email: string,
    credential: Record<string, unknown>,
): Promise<{ id: string }> {
    const text = await apiRequest('/v1/auth/passkeys/login', { method: 'POST', body: { email, credential } });

    return parseId(text);
}

export async function logout(): Promise<void> {
    await apiRequest('/v1/auth/logout', { method: 'POST' });
}

export async function fetchMfaStatus(): Promise<MfaStatus> {
    const text = await apiRequest('/v1/auth/mfa/status');

    return parseMfaStatus(text);
}

export async function enrollTotp(): Promise<TotpEnrollment> {
    const text = await apiRequest('/v1/auth/mfa/totp/enroll', { method: 'POST' });

    return parseTotpEnrollment(text);
}

export async function confirmTotpEnrollment(code: string): Promise<string[]> {
    const text = await apiRequest('/v1/auth/mfa/totp/confirm', { method: 'POST', body: { code } });

    return parseRecoveryCodes(text);
}

export async function regenerateRecoveryCodes(): Promise<string[]> {
    const text = await apiRequest('/v1/auth/mfa/recovery-codes/regenerate', { method: 'POST' });

    return parseRecoveryCodes(text);
}

export async function requestPasskeyRegistrationOptions(): Promise<WebauthnOptionsJson> {
    const text = await apiRequest('/v1/auth/passkeys/registration/options', { method: 'POST' });

    return parseWebauthnOptions(text);
}

export async function completePasskeyRegistration(
    credential: Record<string, unknown>,
    name: string | null,
): Promise<{ id: string }> {
    const text = await apiRequest('/v1/auth/passkeys/registration', { method: 'POST', body: { credential, name } });

    return parseId(text);
}

export async function listSessions(): Promise<SessionSummary[]> {
    const text = await apiRequest('/v1/auth/sessions');

    return parseSessionList(text);
}

export async function revokeSession(sessionId: string): Promise<void> {
    await apiRequest(`/v1/auth/sessions/${encodeURIComponent(sessionId)}`, { method: 'DELETE' });
}

export async function revokeAllSessions(keepCurrent: boolean): Promise<number> {
    const text = await apiRequest(`/v1/auth/sessions?keep_current=${keepCurrent ? 'true' : 'false'}`, {
        method: 'DELETE',
    });

    return parseJson(text, vObject({ revoked_count: vNumber })).revoked_count;
}
