"""Reading a statement file in a disposable process — see `processing.isolation`.

The readers in `processing.statements.parse` are called through here and nowhere else in the
service. They used to run in the service's own process, where a workbook that unpacked to gigabytes
of empty rows could exhaust its memory and stop document reading and statement import for every
taxpayer at once. A file that runs out of memory or time now fails alone, refused as too large —
the remedy a person has is the same either way: export a smaller file.
"""

from processing.isolation import IsolatedTimeoutError, IsolatedWorkerDiedError, run_isolated
from processing.statements._worker import read_worker
from processing.statements.config import StatementParseSettings
from processing.statements.errors import FileTooLargeError, StatementParseError
from processing.statements.parse import ParsedStatement

_TOO_LARGE = "The file is too large to read."


def parse_statement_isolated(
    content: bytes, sheet: str | None, settings: StatementParseSettings
) -> ParsedStatement:
    """`parse_statement`, in its own process."""
    result = _read("parse", content, sheet, settings)

    if not isinstance(result, ParsedStatement):
        raise StatementParseError(f"worker returned an unexpected result: {type(result).__name__}")

    return result


def check_statement_isolated(content: bytes, settings: StatementParseSettings) -> str:
    """`check_statement`, in its own process."""
    result = _read("check", content, None, settings)

    if not isinstance(result, str):
        raise StatementParseError(f"worker returned an unexpected result: {type(result).__name__}")

    return result


def _read(
    operation: str, content: bytes, sheet: str | None, settings: StatementParseSettings
) -> object:
    try:
        status, payload = run_isolated(
            read_worker,
            (operation, content, sheet, settings),
            timeout_seconds=settings.parse_timeout_seconds,
        )
    except (IsolatedTimeoutError, IsolatedWorkerDiedError) as exc:
        raise FileTooLargeError(_TOO_LARGE) from exc

    if status == "ok":
        return payload
    if status == "refused" and isinstance(payload, StatementParseError):
        raise payload
    if status == "too_large":
        raise FileTooLargeError(_TOO_LARGE)

    raise StatementParseError(f"unexpected reader failure: status={status!r}")
