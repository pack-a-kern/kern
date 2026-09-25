import {
    ValidationError,
    isRecord,
    parseJson,
    vArray,
    vBoolean,
    vNullable,
    vNumber,
    vObject,
    vOptional,
    vString,
} from '../lib/validate';

export interface LoginResult {
    mfaRequired: boolean;
    userId: string | null;
}

/**
 * `POST /api/v1/auth/login` response — `Kern\Platform\Http\Controllers\Auth\LoginController`.
 *
 * `id` is **optional, not nullable**. When a second factor is required the controller returns
 * `{"mfa_required": true}` and omits the key entirely — it has not established a session, so there
 * is no user to name yet. Declaring it `vNullable` demanded the key be present, so parsing threw
 * and the screen reported "Sign-in failed" for a perfectly successful first step, leaving anyone
 * with TOTP enrolled unable to sign in at all.
 */
export function parseLoginResult(text: string): LoginResult {
    const parsed = parseJson(
        text,
        vObject({
            mfa_required: vBoolean,
            id: vOptional(vNullable(vString)),
        }),
    );

    return { mfaRequired: parsed.mfa_required, userId: parsed.id ?? null };
}

/** `Kern\Platform\Http\Controllers\Auth\MfaStatusController`. */
export interface MfaStatus {
    totpEnrolled: boolean;
    passkeysCount: number;
    recoveryCodesRemaining: number;
}

export function parseMfaStatus(text: string): MfaStatus {
    const parsed = parseJson(
        text,
        vObject({
            totp_enrolled: vBoolean,
            passkeys_count: vNumber,
            recovery_codes_remaining: vNumber,
        }),
    );

    return {
        totpEnrolled: parsed.totp_enrolled,
        passkeysCount: parsed.passkeys_count,
        recoveryCodesRemaining: parsed.recovery_codes_remaining,
    };
}

/** `Kern\Platform\Http\Controllers\Auth\TotpController::enroll` response. */
export interface TotpEnrollment {
    secret: string;
    provisioningUri: string;
}

export function parseTotpEnrollment(text: string): TotpEnrollment {
    const parsed = parseJson(
        text,
        vObject({
            secret: vString,
            provisioning_uri: vString,
        }),
    );

    return { secret: parsed.secret, provisioningUri: parsed.provisioning_uri };
}

export function parseRecoveryCodes(text: string): string[] {
    return parseJson(text, vObject({ recovery_codes: vArray(vString) })).recovery_codes;
}

export interface SessionSummary {
    id: string;
    ipAddress: string | null;
    userAgent: string | null;
    lastUsedAt: string;
    createdAt: string;
    isCurrent: boolean;
}

export function parseSessionList(text: string): SessionSummary[] {
    const parsed = parseJson(
        text,
        vObject({
            sessions: vArray(
                vObject({
                    id: vString,
                    ip_address: vNullable(vString),
                    user_agent: vNullable(vString),
                    last_used_at: vString,
                    created_at: vString,
                    is_current: vBoolean,
                }),
            ),
        }),
    );

    return parsed.sessions.map((session) => ({
        id: session.id,
        ipAddress: session.ip_address,
        userAgent: session.user_agent,
        lastUsedAt: session.last_used_at,
        createdAt: session.created_at,
        isCurrent: session.is_current,
    }));
}

/** Raw pass-through: the exact WebAuthn `PublicKeyCredentialCreationOptionsJSON`/
 * `PublicKeyCredentialRequestOptionsJSON` shape `Kern\Platform\Identity\Support\Webauthn\
 * PasskeyManager` serializes via `web-auth/webauthn-lib`'s own Symfony serializer — this app does
 * not re-validate its internals field-by-field (that is `@simplewebauthn/browser`'s job at the
 * point it is handed to `navigator.credentials.{create,get}()`), only that it is a JSON object. */
export type WebauthnOptionsJson = Record<string, unknown>;

export function parseWebauthnOptions(text: string): WebauthnOptionsJson {
    return parseJson(text, (value, path = '') => {
        if (!isRecord(value)) {
            throw new ValidationError(path, 'expected a WebAuthn options object');
        }

        return value;
    });
}
