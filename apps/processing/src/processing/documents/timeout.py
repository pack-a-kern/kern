"""Process-isolated, wall-clock-bounded PDF parsing — the decompression-bomb
defence.

Every PDF is parsed and text-extracted in a fresh, disposable subprocess
with a memory ceiling (`_worker.extract_worker`); if it does not finish
within the configured timeout, the process is killed rather than waited on.
This also gives each document its own process boundary, matching the
isolation philosophy `BLUEPRINT.md` §3.4/§13.2 already applies to SPV browser
sessions: nothing survives between documents, and a crash or runaway
allocation in the PDF parser cannot affect the calling process.

See `docs/handoff/019-pdf-text-extraction.md` for why a subprocess-kill
timeout — rather than trying to special-case every possible bomb shape
(nested compression, pathological content-stream operator counts, ...) — is
the primary defence: it bounds worst-case CPU/wall time regardless of which
internal mechanism a pathological file exploits.
"""

import multiprocessing
from multiprocessing.connection import Connection
from multiprocessing.process import BaseProcess

from processing.documents._worker import WorkerPage, WorkerResult, count_worker, extract_worker
from processing.documents.errors import (
    EncryptedPdfRejectedError,
    ExtractionTimeoutError,
    MalformedPdfError,
    TooManyPagesError,
)

# `spawn`, not `fork`: this process may hold open network connections/threads
# (an ASGI server) that must not be duplicated into the child. Slightly
# slower to start than `fork`, and worth it for that safety.
_MP_CONTEXT = multiprocessing.get_context("spawn")


def count_pages_isolated(
    pdf_bytes: bytes,
    *,
    max_memory_bytes: int,
    timeout_seconds: float,
) -> int:
    """Runs `count_worker` in an isolated subprocess and returns the page count.

    Raises `ExtractionTimeoutError`, `EncryptedPdfRejectedError` or
    `MalformedPdfError` — never returns a guess.
    """
    parent_conn, child_conn = _MP_CONTEXT.Pipe(duplex=False)
    process = _MP_CONTEXT.Process(
        target=count_worker,
        args=(pdf_bytes, max_memory_bytes, child_conn),
        daemon=True,
    )
    process.start()
    child_conn.close()

    status, payload = _receive_within_timeout(parent_conn, process, timeout_seconds)

    if status == "ok":
        if not isinstance(payload, int):
            raise MalformedPdfError(
                f"worker returned unexpected payload shape for status 'ok': {payload!r}"
            )
        return payload
    if status == "encrypted":
        raise EncryptedPdfRejectedError("PDF is encrypted; rejected without attempting a password")
    if status == "malformed":
        raise MalformedPdfError(str(payload))
    raise MalformedPdfError(f"unexpected parser failure: status={status!r} payload={payload!r}")


def parse_and_extract_pages(
    pdf_bytes: bytes,
    *,
    max_pages: int,
    max_memory_bytes: int,
    timeout_seconds: float,
) -> list[WorkerPage]:
    """Runs `extract_worker` in an isolated subprocess and returns its pages.

    Raises `ExtractionTimeoutError`, `EncryptedPdfRejectedError`,
    `TooManyPagesError`, or `MalformedPdfError` — never returns a partial or
    best-effort result.
    """
    parent_conn, child_conn = _MP_CONTEXT.Pipe(duplex=False)
    process = _MP_CONTEXT.Process(
        target=extract_worker,
        args=(pdf_bytes, max_pages, max_memory_bytes, child_conn),
        daemon=True,
    )
    process.start()
    child_conn.close()  # only the child's copy of the write end is used

    status, payload = _receive_within_timeout(parent_conn, process, timeout_seconds)

    if status == "ok":
        if not isinstance(payload, list):
            raise MalformedPdfError(
                f"worker returned unexpected payload shape for status 'ok': {payload!r}"
            )
        return payload
    if status == "encrypted":
        raise EncryptedPdfRejectedError("PDF is encrypted; rejected without attempting a password")
    if status == "too_many_pages":
        raise TooManyPagesError(f"PDF has {payload} pages, exceeding the configured limit")
    if status == "malformed":
        raise MalformedPdfError(str(payload))
    raise MalformedPdfError(f"unexpected parser failure: status={status!r} payload={payload!r}")


def _receive_within_timeout(
    parent_conn: Connection, process: BaseProcess, timeout_seconds: float
) -> WorkerResult:
    try:
        if not parent_conn.poll(timeout_seconds):
            _kill(process)
            raise ExtractionTimeoutError(
                f"PDF parsing/extraction exceeded {timeout_seconds}s and was aborted"
            )
        try:
            result: WorkerResult = parent_conn.recv()
        except EOFError as exc:
            # The worker died without sending anything — e.g. killed by the
            # kernel OOM-killer under a container memory cgroup limit
            # (`BLUEPRINT.md` §3.4), independent of our own RLIMIT_AS. Report
            # it, never let a raw EOFError escape this module.
            raise MalformedPdfError(
                f"worker process terminated unexpectedly (exit code {process.exitcode})"
            ) from exc
        return result
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
