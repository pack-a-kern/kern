"""PDF native text-layer extraction and the OCR routing decision (ADR 0005).

The public entry point is `extract_document`. For a given PDF it enforces
the file-security controls `BLUEPRINT.md` §14.2 requires, extracts each page's
native text inside an isolated, timeout-bounded subprocess (§14.3 —
"OCR-first is forbidden"), scores extraction quality against measurable,
configurable signals, and returns a structured decision on whether each
page needs OCR. It never calls OCR or any external provider itself — ADR
0005 gates that behind per-subject consent and an audit event, which is
Milestone 7's job, not this one's.

See `docs/handoff/019-pdf-text-extraction.md` for the full contract: the
exact quality thresholds and why they were chosen, the shape Laravel/M7
should expect, and the prompt-injection handling rules for
`UntrustedDocumentText`.
"""

from processing.documents.config import DocumentExtractionSettings
from processing.documents.errors import (
    DocumentExtractionError,
    EmptyDocumentError,
    EncryptedPdfRejectedError,
    ExtractionTimeoutError,
    FileTooLargeError,
    InvalidMagicBytesError,
    MalformedPdfError,
    TooManyPagesError,
)
from processing.documents.extraction import extract_document
from processing.documents.schemas import (
    DocumentExtractionResult,
    OcrDecision,
    PageExtractionResult,
    PageQualitySignals,
    UntrustedDocumentText,
)

__all__ = [
    "DocumentExtractionError",
    "DocumentExtractionResult",
    "DocumentExtractionSettings",
    "EmptyDocumentError",
    "EncryptedPdfRejectedError",
    "ExtractionTimeoutError",
    "FileTooLargeError",
    "InvalidMagicBytesError",
    "MalformedPdfError",
    "OcrDecision",
    "PageExtractionResult",
    "PageQualitySignals",
    "TooManyPagesError",
    "UntrustedDocumentText",
    "extract_document",
]
