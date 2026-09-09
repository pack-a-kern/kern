"""The isolated subprocess entry point for PDF parsing/extraction.

This function runs in its own process, spawned by
`processing.documents.timeout.parse_and_extract_pages`, never in the calling
process — so a pathological PDF's worst-case CPU or memory consumption is
bounded by killing this process, not by hoping `pypdf` gives up on its own.
See `docs/handoff/019-pdf-text-extraction.md`.

Module-level (not nested) by necessity: the `spawn` multiprocessing start
method pickles a reference to this function by its import path, so it must
be importable as `processing.documents._worker.extract_worker`.
"""

import contextlib
import io
import resource
from multiprocessing.connection import Connection

from pypdf import PdfReader
from pypdf.errors import PyPdfError

# One page: (mediabox width in points, mediabox height in points, extracted text).
WorkerPage = tuple[float, float, str]

# Sent back to the parent as exactly one `(status, payload)` message:
#   ("ok", list[WorkerPage])   - success
#   ("encrypted", None)        - PDF is encrypted; never decrypted, never given /pages access
#   ("too_many_pages", int)    - page count, which exceeded the configured limit
#   ("malformed", str)         - a pypdf-recognised parse failure, with its message
#   ("error", str)             - an exception pypdf itself does not define; still reported
WorkerResult = tuple[str, int | str | list[WorkerPage] | None]


def extract_worker(
    pdf_bytes: bytes, max_pages: int, max_memory_bytes: int, conn: Connection
) -> None:
    """Parses `pdf_bytes` and extracts every page's text, entirely in this
    process. Sends exactly one `WorkerResult` tuple back and exits. Never
    raises out of this function — every failure path is reported through
    `conn`, because there is nothing else listening once this process ends.
    """
    # Best-effort: some sandboxes refuse RLIMIT_AS. The wall-clock timeout
    # applied by the parent still bounds the worst case either way.
    with contextlib.suppress(ValueError, OSError):
        resource.setrlimit(resource.RLIMIT_AS, (max_memory_bytes, max_memory_bytes))
    try:
        reader = PdfReader(io.BytesIO(pdf_bytes))
        if reader.is_encrypted:
            # Never call .decrypt(), not even with an empty password —
            # `BLUEPRINT.md` §14.2 requires rejecting encrypted PDFs outright.
            conn.send(("encrypted", None))
            return
        page_count = len(reader.pages)
        if page_count > max_pages:
            conn.send(("too_many_pages", page_count))
            return
        pages: list[WorkerPage] = [
            (float(page.mediabox.width), float(page.mediabox.height), page.extract_text())
            for page in reader.pages
        ]
        conn.send(("ok", pages))
    except PyPdfError as exc:
        conn.send(("malformed", str(exc)))
    except MemoryError as exc:
        conn.send(("malformed", f"memory limit exceeded: {exc}"))
    except Exception as exc:  # deliberately broad: reported to the parent, never swallowed
        conn.send(("error", repr(exc)))
    finally:
        conn.close()
