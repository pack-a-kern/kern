"""Structured JSON logging with a request-correlation ID (`BLUEPRINT.md` §23).

Log records carry only a fixed, reviewed set of fields (`_ALLOWED_EXTRA_FIELDS`).
This is deliberate: it is the mechanism that keeps PII out of logs
(`docs/AGENTS.md` §8) — a caller cannot accidentally leak a document body or
a subject identifier through `extra=` because unlisted keys are dropped
rather than serialized.
"""

import contextvars
import json
import logging
from datetime import UTC, datetime
from typing import Any, Final

correlation_id_var: contextvars.ContextVar[str | None] = contextvars.ContextVar(
    "correlation_id", default=None
)

_ALLOWED_EXTRA_FIELDS: Final[tuple[str, ...]] = (
    "correlation_id",
    "event",
    "method",
    "path",
    "status_code",
    "duration_ms",
    # `processing.documents.http` — the extraction decision and page count only, never
    # extracted text (`BLUEPRINT.md` §4.10, §23 — "no PII in logs").
    "page_count",
    "overall_decision",
    "error_code",
)


class CorrelationIdLogFilter(logging.Filter):
    """Stamps every record with the request-scoped correlation id, if any."""

    def filter(self, record: logging.LogRecord) -> bool:
        record.correlation_id = correlation_id_var.get()
        return True


class JsonLogFormatter(logging.Formatter):
    """Renders one JSON object per line; never dumps `record.__dict__` raw."""

    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, Any] = {
            "timestamp": datetime.fromtimestamp(record.created, tz=UTC).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
        }
        for field in _ALLOWED_EXTRA_FIELDS:
            value = getattr(record, field, None)
            if value is not None:
                payload[field] = value
        if record.exc_info is not None:
            payload["exc_info"] = self.formatException(record.exc_info)
        return json.dumps(payload, default=str)


def configure_logging(level: int = logging.INFO) -> None:
    """Replaces the root logger's handlers with a single JSON stream handler.

    Idempotent: safe to call more than once (e.g. once from app startup, once
    from a test fixture) without duplicating log lines.
    """
    root = logging.getLogger()
    root.setLevel(level)
    root.handlers.clear()

    handler = logging.StreamHandler()
    handler.setFormatter(JsonLogFormatter())
    handler.addFilter(CorrelationIdLogFilter())
    root.addHandler(handler)
