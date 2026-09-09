"""PDF text-layer extraction and the OCR routing decision (ADR 0005,
`BLUEPRINT.md` §14.3).

`extract_document` is the single public entry point: given raw PDF bytes,
it enforces every file-security control (§14.2), extracts native text per
page inside an isolated, timeout-bounded subprocess, scores each page's
extraction quality against measurable signals, and returns a decision — per
page and overall — on whether OCR (ADR 0005's external, consent-gated path)
is needed. It never calls OCR or any external service itself; it only
decides whether that path is required.
"""

from processing.documents.config import DocumentExtractionSettings
from processing.documents.errors import EmptyDocumentError
from processing.documents.quality import (
    compute_quality_score,
    compute_quality_signals,
    decide_needs_ocr,
)
from processing.documents.schemas import (
    DocumentExtractionResult,
    OcrDecision,
    PageExtractionResult,
    UntrustedDocumentText,
)
from processing.documents.security import validate_file_size, validate_magic_bytes
from processing.documents.timeout import parse_and_extract_pages

_POINTS_PER_INCH = 72.0


def extract_document(
    pdf_bytes: bytes,
    *,
    declared_filename: str | None = None,
    settings: DocumentExtractionSettings | None = None,
) -> DocumentExtractionResult:
    """Validates, parses and scores a PDF's text layer.

    `declared_filename` is accepted for logging/error-message context only
    — it never influences whether the file is accepted (§14.2: "ignore
    supplied MIME"; validation is driven entirely by `pdf_bytes`).

    Raises a `processing.documents.errors.DocumentExtractionError` subclass
    for every rejection; never returns a partial or best-effort result for a
    file it refused.
    """
    settings = settings or DocumentExtractionSettings()

    validate_file_size(pdf_bytes, settings)
    validate_magic_bytes(pdf_bytes, settings)

    raw_pages = parse_and_extract_pages(
        pdf_bytes,
        max_pages=settings.max_pages,
        max_memory_bytes=settings.max_worker_memory_bytes,
        timeout_seconds=settings.parse_timeout_seconds,
    )
    if not raw_pages:
        raise EmptyDocumentError("PDF has zero pages")

    pages: list[PageExtractionResult] = []
    for index, (width_points, height_points, text) in enumerate(raw_pages, start=1):
        area_sq_inches = (width_points / _POINTS_PER_INCH) * (height_points / _POINTS_PER_INCH)
        signals = compute_quality_signals(text, area_sq_inches)
        score = compute_quality_score(signals, settings)
        needs_ocr = decide_needs_ocr(signals, score, settings)
        pages.append(
            PageExtractionResult(
                page_number=index,
                text=UntrustedDocumentText(text),
                quality_signals=signals,
                quality_score=score,
                needs_ocr=needs_ocr,
            )
        )

    pages_needing_ocr = [page.page_number for page in pages if page.needs_ocr]
    overall_decision = (
        OcrDecision.OCR_REQUIRED if pages_needing_ocr else OcrDecision.NATIVE_TEXT_SUFFICIENT
    )
    return DocumentExtractionResult(
        page_count=len(pages),
        pages=pages,
        overall_decision=overall_decision,
        pages_needing_ocr=pages_needing_ocr,
    )
