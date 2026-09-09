"""Integration tests for `extract_document` — the single public entry
point. Each test asserts the *decision*, not just that parsing didn't
crash (`docs/AGENTS.md` §2)."""

import io

import pytest
from pdf_builders import (
    build_blank_pdf,
    build_encrypted_pdf,
    build_garbled_text_pdf,
    build_mixed_pdf,
    build_numeric_only_pdf,
    build_sparse_but_real_pdf,
    build_text_pdf,
)
from pypdf import PdfWriter

from processing.documents.config import DocumentExtractionSettings
from processing.documents.errors import (
    EmptyDocumentError,
    EncryptedPdfRejectedError,
    FileTooLargeError,
    InvalidMagicBytesError,
    TooManyPagesError,
)
from processing.documents.extraction import extract_document
from processing.documents.schemas import OcrDecision


def test_a_clean_text_layer_pdf_needs_no_ocr() -> None:
    result = extract_document(build_text_pdf())

    assert result.page_count == 1
    assert result.overall_decision == OcrDecision.NATIVE_TEXT_SUFFICIENT
    assert result.pages_needing_ocr == []
    assert not result.pages[0].needs_ocr
    assert "Factura" in result.pages[0].text.reveal()


def test_a_scanned_image_only_pdf_needs_ocr() -> None:
    result = extract_document(build_blank_pdf())

    assert result.overall_decision == OcrDecision.OCR_REQUIRED
    assert result.pages_needing_ocr == [1]
    assert result.pages[0].needs_ocr
    assert result.pages[0].quality_signals.is_whitespace_only
    assert result.pages[0].text.reveal() == ""


def test_a_mixed_document_flags_only_the_pages_that_need_ocr() -> None:
    result = extract_document(build_mixed_pdf())

    assert result.page_count == 3
    assert result.overall_decision == OcrDecision.OCR_REQUIRED
    assert result.pages_needing_ocr == [2]
    assert not result.pages[0].needs_ocr
    assert result.pages[1].needs_ocr
    assert not result.pages[2].needs_ocr


def test_a_garbled_extraction_is_routed_to_ocr_despite_a_nonempty_text_layer() -> None:
    """This is the ADR 0008 case: `pypdf` can return non-empty but
    unreliable text on complex/non-standard encodings. Quality scoring,
    not mere presence of characters, decides whether OCR is still needed."""
    result = extract_document(build_garbled_text_pdf())

    assert result.overall_decision == OcrDecision.OCR_REQUIRED
    assert result.pages[0].quality_signals.non_whitespace_char_count > 0
    assert result.pages[0].needs_ocr


def test_a_short_but_real_line_of_text_does_not_need_ocr() -> None:
    result = extract_document(build_sparse_but_real_pdf())

    assert result.overall_decision == OcrDecision.NATIVE_TEXT_SUFFICIENT
    assert not result.pages[0].needs_ocr


def test_a_numeric_only_page_is_routed_to_ocr_a_documented_limitation() -> None:
    result = extract_document(build_numeric_only_pdf())

    assert result.overall_decision == OcrDecision.OCR_REQUIRED
    assert result.pages[0].needs_ocr


def test_an_encrypted_pdf_is_rejected_outright() -> None:
    with pytest.raises(EncryptedPdfRejectedError):
        extract_document(build_encrypted_pdf())


def test_an_oversized_file_is_rejected_before_parsing() -> None:
    settings = DocumentExtractionSettings(max_file_size_bytes=500)

    with pytest.raises(FileTooLargeError):
        extract_document(build_text_pdf(), settings=settings)


def test_too_many_pages_is_rejected() -> None:
    settings = DocumentExtractionSettings(max_pages=2)

    with pytest.raises(TooManyPagesError):
        extract_document(build_mixed_pdf(), settings=settings)


def test_content_with_no_pdf_signature_is_rejected() -> None:
    with pytest.raises(InvalidMagicBytesError):
        extract_document(b"not a pdf at all")


def test_declared_filename_never_influences_acceptance() -> None:
    """A caller-declared filename must be pure observability, never a
    validation input (§14.2 — "ignore supplied MIME")."""
    result_with_lying_filename = extract_document(build_text_pdf(), declared_filename="virus.exe")
    result_without_filename = extract_document(build_text_pdf())

    assert result_with_lying_filename.overall_decision == result_without_filename.overall_decision


def test_a_valid_pdf_with_zero_pages_is_reported_as_empty() -> None:
    writer = PdfWriter()  # no pages added
    buffer = io.BytesIO()
    writer.write(buffer)

    with pytest.raises(EmptyDocumentError):
        extract_document(buffer.getvalue())


def test_settings_thresholds_are_real_inputs_not_hard_coded_constants() -> None:
    """Proves the quality gate is a configured value, not a baked-in
    constant: the same sparse-but-real page passes with the default
    `min_quality_score` and fails once that threshold is raised."""
    lenient = extract_document(build_sparse_but_real_pdf())
    strict_settings = DocumentExtractionSettings(min_quality_score=0.9)
    strict = extract_document(build_sparse_but_real_pdf(), settings=strict_settings)

    assert lenient.overall_decision == OcrDecision.NATIVE_TEXT_SUFFICIENT
    assert strict.overall_decision == OcrDecision.OCR_REQUIRED
