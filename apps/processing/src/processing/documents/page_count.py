"""How many pages a PDF has, and nothing else.

Counting does not read a page: `pypdf` walks the page tree, which is why a
300-page file answers in ~24 ms where extracting its text takes four times
as long and reading it with a document-understanding pipeline takes minutes.
That gap is the whole point — it lets a document be refused for being too
long *before* anything expensive, local or remote, has looked at it.

The same isolation as text extraction, for the same reason: a hostile PDF
should exhaust a spawned process with a memory ceiling and a deadline, never
the service itself. What is counted is still never trusted — size and magic
bytes are checked first, exactly as `extract_document` checks them.
"""

from processing.documents.config import DocumentExtractionSettings
from processing.documents.security import validate_file_size, validate_magic_bytes
from processing.documents.timeout import count_pages_isolated


def count_pdf_pages(pdf_bytes: bytes, *, settings: DocumentExtractionSettings | None = None) -> int:
    """Validates the bytes are a PDF this service accepts, and counts its pages.

    Raises a `processing.documents.errors.DocumentExtractionError` subclass for
    every rejection, so a caller can tell "this document is bad" from "the
    service failed" — never returns a guess.
    """
    settings = settings or DocumentExtractionSettings()

    validate_file_size(pdf_bytes, settings)
    validate_magic_bytes(pdf_bytes, settings)

    return count_pages_isolated(
        pdf_bytes,
        max_memory_bytes=settings.max_worker_memory_bytes,
        timeout_seconds=settings.parse_timeout_seconds,
    )
