"""The isolated subprocess entry point for reading a statement file.

Runs in its own process, started by `processing.statements.isolated`, never in the service — so a
workbook or PDF that unpacks to gigabytes exhausts this process and nothing else. Module-level by
necessity: the `spawn` start method imports it by path.
"""

from multiprocessing.connection import Connection

from processing.isolation import limit_memory
from processing.statements.config import StatementParseSettings
from processing.statements.errors import StatementParseError
from processing.statements.parse import check_statement, parse_statement

# Sent back as exactly one `(status, payload)` message:
#   ("ok", ParsedStatement | str)   - the statement, or the checked format
#   ("refused", StatementParseError) - a refusal the person can act on, as the reader raised it
#   ("too_large", str)               - the reader ran out of memory
#   ("error", str)                   - anything else; still reported, never swallowed


def read_worker(
    operation: str,
    content: bytes,
    sheet: str | None,
    settings: StatementParseSettings,
    conn: Connection,
) -> None:
    """`parse` or `check` one file, in this process. Never raises: there is nothing else listening
    once it ends, so every outcome goes through `conn`."""
    limit_memory(settings.max_worker_memory_bytes)
    try:
        result = (
            parse_statement(content, sheet, settings)
            if operation == "parse"
            else check_statement(content, settings)
        )
        conn.send(("ok", result))
    except StatementParseError as exc:
        conn.send(("refused", exc))
    except MemoryError:
        conn.send(("too_large", "memory limit exceeded"))
    except Exception as exc:  # deliberately broad: reported to the parent, never swallowed
        conn.send(("error", repr(exc)))
    finally:
        conn.close()
