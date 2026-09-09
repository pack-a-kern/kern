"""Exceptions for PDF text-layer extraction (`BLUEPRINT.md` §14.2, §14.3).

Every failure mode here is a rejection, not a best-effort fallback: a file
that fails a security check is refused before any expensive parsing
happens, and a file that fails to parse is reported as such — never
silently treated as empty or blank (`docs/AGENTS.md` §2, "never fabricate").
"""


class DocumentExtractionError(Exception):
    """Base class for every failure this package can raise."""


class FileTooLargeError(DocumentExtractionError):
    """The input exceeds `DocumentExtractionSettings.max_file_size_bytes`."""


class InvalidMagicBytesError(DocumentExtractionError):
    """The content bytes carry no PDF signature.

    Raised regardless of any caller-declared filename or content type
    (`BLUEPRINT.md` §14.2 — "ignore supplied MIME").
    """


class EncryptedPdfRejectedError(DocumentExtractionError):
    """The PDF is encrypted.

    Rejected outright per §14.2 ("reject encrypted PDFs ... unless user
    provides explicit password via ephemeral flow") — that ephemeral-
    password flow is not built yet (Milestone 7), so every encrypted PDF is
    rejected today. No password, including the empty string, is ever tried.
    """


class TooManyPagesError(DocumentExtractionError):
    """The PDF has more pages than `DocumentExtractionSettings.max_pages`."""


class EmptyDocumentError(DocumentExtractionError):
    """The PDF parses but has zero pages."""


class MalformedPdfError(DocumentExtractionError):
    """The PDF could not be parsed, or the parser failed unexpectedly."""


class ExtractionTimeoutError(DocumentExtractionError):
    """Parsing/extraction did not finish within `parse_timeout_seconds`.

    This is the decompression-bomb defence: a file that is only a few
    kilobytes on disk can still make pure-Python content-stream tokenizing
    run for minutes (see `docs/handoff/019-pdf-text-extraction.md`). A
    wall-clock budget with a hard process kill bounds worst-case CPU/memory
    regardless of which internal mechanism a pathological file exploits —
    it does not depend on recognising the mechanism in advance.
    """
