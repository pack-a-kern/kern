"""Per-request correlation ID (`BLUEPRINT.md` §23).

Reuses an inbound `X-Request-Id` (Laravel/an internal caller may already
have one), otherwise mints a new one. The id is echoed back on the response
and attached to every log line emitted while handling the request, and to
nothing else — never a request body, never headers other than this one.
"""

import logging
import time
import uuid

from starlette.middleware.base import BaseHTTPMiddleware, RequestResponseEndpoint
from starlette.requests import Request
from starlette.responses import Response

from processing.logging_config import correlation_id_var

logger = logging.getLogger("processing.request")

_REQUEST_ID_HEADER = "X-Request-Id"


class CorrelationIdMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next: RequestResponseEndpoint) -> Response:
        correlation_id = request.headers.get(_REQUEST_ID_HEADER) or str(uuid.uuid4())
        token = correlation_id_var.set(correlation_id)
        started_at = time.perf_counter()
        try:
            response = await call_next(request)
            duration_ms = round((time.perf_counter() - started_at) * 1000, 2)
            response.headers[_REQUEST_ID_HEADER] = correlation_id
            logger.info(
                "http_request",
                extra={
                    "event": "http_request",
                    "method": request.method,
                    "path": request.url.path,
                    "status_code": response.status_code,
                    "duration_ms": duration_ms,
                },
            )
            return response
        finally:
            correlation_id_var.reset(token)
