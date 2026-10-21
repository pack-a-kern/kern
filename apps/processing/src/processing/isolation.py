"""Running a parser on an untrusted file in a disposable process, with a memory ceiling and a clock.

The decompression-bomb defence for everything this service reads. A PDF, a workbook and a CSV can
each be built small on disk and enormous once read, and no list of special cases catches every shape
of that — so the parser runs in a fresh child process instead, with `RLIMIT_AS` set inside it and a
wall-clock budget outside it. Exceeding either kills the child; the service, and every other request
in it, is untouched. See `docs/handoff/019-pdf-text-extraction.md` for why a kill, not a check, is
the primary defence.

Shared by the document reader (`processing.documents.timeout`) and the statement reader
(`processing.statements.isolated`), which differ only in what they parse and what their errors are
called. The contract a worker keeps: it receives its arguments and a `Connection`, sends exactly one
`(status, payload)` message, never raises, and calls `limit_memory` before touching the file.
"""

import contextlib
import multiprocessing
import resource
from collections.abc import Callable
from multiprocessing.process import BaseProcess

# `spawn`, not `fork`: this process may hold open network connections/threads
# (an ASGI server) that must not be duplicated into the child. Slightly
# slower to start than `fork`, and worth it for that safety.
_MP_CONTEXT = multiprocessing.get_context("spawn")

WorkerMessage = tuple[str, object]


class IsolatedTimeoutError(Exception):
    """The worker did not answer within its budget, and was killed."""


class IsolatedWorkerDiedError(Exception):
    """The worker ended without answering — the kernel's OOM killer, usually, under the container's
    own memory limit, which applies whatever `RLIMIT_AS` allowed."""


def limit_memory(max_bytes: int) -> None:
    """Called first inside a worker. Best-effort: some sandboxes refuse `RLIMIT_AS`, and the
    wall-clock budget still bounds the worst case when they do."""
    with contextlib.suppress(ValueError, OSError):
        resource.setrlimit(resource.RLIMIT_AS, (max_bytes, max_bytes))


def run_isolated(
    worker: Callable[..., None], args: tuple[object, ...], *, timeout_seconds: float
) -> WorkerMessage:
    """Runs `worker(*args, connection)` in a fresh process and returns the one message it sends.

    The worker must be a module-level function: `spawn` pickles a reference to it by import path.
    """
    parent_conn, child_conn = _MP_CONTEXT.Pipe(duplex=False)
    process = _MP_CONTEXT.Process(target=worker, args=(*args, child_conn), daemon=True)
    process.start()
    child_conn.close()  # only the child's copy of the write end is used

    try:
        if not parent_conn.poll(timeout_seconds):
            _kill(process)
            raise IsolatedTimeoutError(f"exceeded {timeout_seconds}s and was aborted")
        try:
            message: WorkerMessage = parent_conn.recv()
        except EOFError as exc:
            raise IsolatedWorkerDiedError(
                f"worker process terminated unexpectedly (exit code {process.exitcode})"
            ) from exc
        return message
    finally:
        parent_conn.close()
        process.join(timeout=2)
        if process.is_alive():
            _kill(process)


def _kill(process: BaseProcess) -> None:
    process.terminate()
    process.join(timeout=2)
    if process.is_alive():
        process.kill()
        process.join()
