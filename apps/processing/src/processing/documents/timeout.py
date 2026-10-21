"""Process-isolated, wall-clock-bounded PDF parsing — the decompression-bomb
defence.

Every PDF is parsed and text-extracted in a fresh, disposable subprocess
with a memory ceiling (`_worker.extract_worker`, run by
`processing.isolation.run_isolated`); if it does not finish within the
configured timeout, the process is killed rather than waited on.
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

from typing import cast

from processing.documents._worker import WorkerPage, count_worker, extract_worker
from processing.documents.errors import (
    EncryptedPdfRejectedError,
    ExtractionTimeoutError,
    MalformedPdfError,
    TooManyPagesError,
)
from processing.isolation import (
    IsolatedTimeoutError,
    IsolatedWorkerDiedError,
    WorkerMessage,
    run_isolated,
)


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
    status, payload = _run(count_worker, (pdf_bytes, max_memory_bytes), timeout_seconds)

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
    status, payload = _run(
        extract_worker, (pdf_bytes, max_pages, max_memory_bytes), timeout_seconds
    )

    if status == "ok":
        if not isinstance(payload, list):
            raise MalformedPdfError(
                f"worker returned unexpected payload shape for status 'ok': {payload!r}"
            )
        # The worker's own contract (`_worker.extract_worker`): a list of WorkerPage tuples.
        return cast("list[WorkerPage]", payload)
    if status == "encrypted":
        raise EncryptedPdfRejectedError("PDF is encrypted; rejected without attempting a password")
    if status == "too_many_pages":
        raise TooManyPagesError(f"PDF has {payload} pages, exceeding the configured limit")
    if status == "malformed":
        raise MalformedPdfError(str(payload))
    raise MalformedPdfError(f"unexpected parser failure: status={status!r} payload={payload!r}")


def _run(worker: object, args: tuple[object, ...], timeout_seconds: float) -> WorkerMessage:
    """`run_isolated`, with its two failures in this module's own terms."""
    try:
        return run_isolated(worker, args, timeout_seconds=timeout_seconds)  # type: ignore[arg-type]
    except IsolatedTimeoutError as exc:
        raise ExtractionTimeoutError(f"PDF parsing/extraction {exc}") from exc
    except IsolatedWorkerDiedError as exc:
        raise MalformedPdfError(str(exc)) from exc
