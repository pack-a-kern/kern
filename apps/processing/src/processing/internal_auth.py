"""Internal-service authentication for endpoints Laravel's `apps/api` calls
directly over the internal network (`BLUEPRINT.md` §1.2, §3.3).

This is a shared-secret bearer token — not the mTLS/signed-JWT scheme
`BLUEPRINT.md` §13.4 describes for the `spv-browser` worker contract. That
scheme has never been decided anywhere in this codebase
(`docs/handoff/004-python-browser-services.md` leaves it explicitly open,
and `docs/handoff/022-document-pipeline.md` built its Laravel-side client
with *no* internal auth at all, for the same reason: nobody had decided one
yet). Inventing mTLS/JWT infrastructure now, with no certificate authority
or token issuer this task has any authority over, would itself be
fabricating an unverified contract (`docs/AGENTS.md` rule 1). A shared
secret compared in constant time is the minimum control that satisfies
"must not be callable unauthenticated" without guessing at infrastructure
this task cannot stand up. Whoever designs the final internal-auth scheme
for every Laravel<->processing call should replace this, not extend it
per-endpoint.
"""

import secrets

from fastapi import Header, HTTPException, status
from pydantic import Field, SecretStr, ValidationError
from pydantic_settings import BaseSettings, SettingsConfigDict

_UNAUTHORIZED_DETAIL_MISSING = {
    "error": "unauthorized",
    "message": "Missing or malformed Authorization header.",
}
_UNAUTHORIZED_DETAIL_INVALID = {
    "error": "unauthorized",
    "message": "Invalid service authentication token.",
}
_UNAUTHORIZED_DETAIL_UNCONFIGURED = {
    "error": "unauthorized",
    "message": "Service authentication is not configured.",
}


class InternalServiceAuthSettings(BaseSettings):
    """Loaded from `INTERNAL_SERVICE_AUTH_TOKEN`. No default: an endpoint
    guarded by `require_internal_auth` fails closed rather than falling back
    to an implicit "no auth configured" open state."""

    model_config = SettingsConfigDict(env_prefix="INTERNAL_SERVICE_", extra="forbid", frozen=True)

    auth_token: SecretStr = Field(
        description="Shared secret the caller sends as `Authorization: Bearer <token>`. "
        "Required — there is no unauthenticated fallback."
    )


def require_internal_auth(authorization: str | None = Header(default=None)) -> None:
    """FastAPI dependency guarding an internal-only endpoint.

    Raises `401` for a missing header, a header not shaped as
    `Bearer <token>`, a token that does not match
    `INTERNAL_SERVICE_AUTH_TOKEN`, or that setting being unset entirely — a
    misconfigured deployment fails closed rather than accepting every
    caller.
    """
    if authorization is None or not authorization.startswith("Bearer "):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail=_UNAUTHORIZED_DETAIL_MISSING
        )

    try:
        settings = InternalServiceAuthSettings()  # pyright: ignore[reportCallIssue]
    except ValidationError as exc:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail=_UNAUTHORIZED_DETAIL_UNCONFIGURED
        ) from exc

    provided = authorization.removeprefix("Bearer ")
    expected = settings.auth_token.get_secret_value()
    if not secrets.compare_digest(provided, expected):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail=_UNAUTHORIZED_DETAIL_INVALID
        )
