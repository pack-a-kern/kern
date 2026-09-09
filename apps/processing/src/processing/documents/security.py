"""Pre-parse file security checks (`BLUEPRINT.md` §14.2).

Both checks run before any PDF parsing and never trust a caller-supplied
filename or content type — only the byte content decides whether input is
accepted.
"""

from processing.documents.config import DocumentExtractionSettings
from processing.documents.errors import FileTooLargeError, InvalidMagicBytesError

_PDF_MAGIC = b"%PDF-"


def validate_file_size(pdf_bytes: bytes, settings: DocumentExtractionSettings) -> None:
    """Rejects oversized input before any parsing happens (§14.2, "maximum
    size per type"). An empty file is not rejected here — it has no PDF
    signature either, so `validate_magic_bytes` catches it uniformly."""
    if len(pdf_bytes) > settings.max_file_size_bytes:
        raise FileTooLargeError(
            f"{len(pdf_bytes)} bytes exceeds the {settings.max_file_size_bytes}-byte limit"
        )


def validate_magic_bytes(pdf_bytes: bytes, settings: DocumentExtractionSettings) -> None:
    """Confirms a PDF signature is present in the content bytes themselves.

    Never inspects a filename or a caller-declared content type (§14.2:
    "ignore supplied MIME"). ISO 32000-2 §7.5.2 allows the `%PDF-x.y` header
    to appear after leading bytes some generators prepend, so this scans a
    bounded window (`magic_byte_scan_window`) rather than requiring the
    signature at offset 0.
    """
    window = pdf_bytes[: settings.magic_byte_scan_window]
    if _PDF_MAGIC not in window:
        raise InvalidMagicBytesError(
            f"content has no PDF signature within the first {settings.magic_byte_scan_window} bytes"
        )
