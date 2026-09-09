"""File-security controls (`BLUEPRINT.md` §14.2). Each test uses a genuinely
hostile input, not a happy-path stub."""

import pytest
from pdf_builders import build_text_pdf

from processing.documents.config import DocumentExtractionSettings
from processing.documents.errors import FileTooLargeError, InvalidMagicBytesError
from processing.documents.extraction import extract_document
from processing.documents.security import validate_file_size, validate_magic_bytes


def test_rejects_a_file_larger_than_the_configured_limit() -> None:
    settings = DocumentExtractionSettings(max_file_size_bytes=100)
    oversized = b"%PDF-1.4\n" + b"0" * 200

    with pytest.raises(FileTooLargeError):
        validate_file_size(oversized, settings)


def test_accepts_a_file_at_exactly_the_limit() -> None:
    settings = DocumentExtractionSettings(max_file_size_bytes=10)
    exactly_ten_bytes = b"0123456789"

    validate_file_size(exactly_ten_bytes, settings)  # must not raise


def test_rejects_content_with_no_pdf_signature() -> None:
    settings = DocumentExtractionSettings()

    with pytest.raises(InvalidMagicBytesError):
        validate_magic_bytes(b"this is just plain text, not a PDF", settings)


def test_rejects_content_whose_real_type_is_a_png_despite_a_pdf_filename() -> None:
    """The security requirement is "never trust a supplied MIME type"
    (§14.2). This proves it end to end: a real PNG signature, wrapped with a
    caller-declared filename that claims otherwise, is still rejected —
    because `extract_document` never looks at `declared_filename` to decide
    validity."""
    png_bytes = b"\x89PNG\r\n\x1a\n" + b"\x00" * 128

    with pytest.raises(InvalidMagicBytesError):
        extract_document(png_bytes, declared_filename="invoice.pdf")


def test_rejects_an_empty_file() -> None:
    settings = DocumentExtractionSettings()

    with pytest.raises(InvalidMagicBytesError):
        validate_magic_bytes(b"", settings)


def test_accepts_the_pdf_signature_after_leading_bytes_within_the_scan_window() -> None:
    """ISO 32000-2 §7.5.2 allows leading bytes before the header; some
    generators prepend a few. This must not be rejected."""
    settings = DocumentExtractionSettings()
    leading_junk = b"\x00\x01\x02" + b"%PDF-1.7\n%rest of file"

    validate_magic_bytes(leading_junk, settings)  # must not raise


def test_rejects_the_pdf_signature_beyond_the_configured_scan_window() -> None:
    settings = DocumentExtractionSettings(magic_byte_scan_window=16)
    signature_too_far_out = b"0" * 100 + b"%PDF-1.7\n"

    with pytest.raises(InvalidMagicBytesError):
        validate_magic_bytes(signature_too_far_out, settings)


def test_a_real_pdf_passes_both_checks() -> None:
    settings = DocumentExtractionSettings()
    real_pdf = build_text_pdf()

    validate_file_size(real_pdf, settings)  # must not raise
    validate_magic_bytes(real_pdf, settings)  # must not raise
