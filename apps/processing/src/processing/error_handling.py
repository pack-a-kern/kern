"""Flat error envelopes for the whole service.

`fastapi.HTTPException(detail=...)` wraps whatever `detail` is into
`{"detail": <detail>}` by default. Every structured error this service
raises (`processing.internal_auth.require_internal_auth`,
`processing.documents.http.extract_text`) already passes a
`{"error": "<code>", "message": "<text>"}` dict as `detail` — this handler
returns that dict as the response body directly, so every error this
service produces (auth failures included) shares one flat shape:
`{"error": "<code>", "message": "<text>"}`. An `HTTPException` raised with a
plain string `detail` (e.g. by a future endpoint that has no reason to use
the structured shape) still falls back to FastAPI's own `{"detail": "..."}`.
"""

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException


async def _http_exception_handler(request: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, StarletteHTTPException)  # narrows the handler's exc type; S101 ignored
    content = exc.detail if isinstance(exc.detail, dict) else {"detail": exc.detail}
    return JSONResponse(status_code=exc.status_code, content=content, headers=exc.headers)


def register_error_handlers(app: FastAPI) -> None:
    app.add_exception_handler(StarletteHTTPException, _http_exception_handler)
