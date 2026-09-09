"""Hand-built PDF fixtures for extraction/security tests.

Constructed with `pypdf.PdfWriter`'s public object API (never a real
document corpus — none exists yet at this stage of the project), so that
each fixture exercises exactly one security or quality signal
deterministically (`docs/AGENTS.md` §2: tests must demonstrate the actual
behaviour, not just "parsing didn't crash").
"""

import io

from pypdf import PageObject, PdfWriter
from pypdf.generic import DecodedStreamObject, DictionaryObject, NameObject

_LETTER_WIDTH = 612.0
_LETTER_HEIGHT = 792.0

# A realistic line of Romanian fiscal-document prose (diacritics omitted —
# the standard Helvetica font's default WinAnsi-ish encoding and this
# fixture builder's Latin-1 content-stream bytes do not need to round-trip
# them for these tests), repeated to reach normal body-text density on a
# Letter-sized page.
_DENSE_TEXT = (
    "Factura nr. 123 din data de 07.09.2026. Suma totala de plata este de "
    "1234,56 RON, conform contractului de inchiriere incheiat intre parti. "
) * 8


def build_text_pdf(
    text: str = _DENSE_TEXT, *, width: float = _LETTER_WIDTH, height: float = _LETTER_HEIGHT
) -> bytes:
    """A single page with a normal, dense, valid text layer."""
    writer = PdfWriter()
    page = writer.add_blank_page(width=width, height=height)
    _attach_text(page, text)
    return _write(writer)


def build_blank_pdf(*, width: float = _LETTER_WIDTH, height: float = _LETTER_HEIGHT) -> bytes:
    """A page with no `/Contents` at all — the "scanned image, no text
    layer" case: `extract_text()` returns an empty string."""
    writer = PdfWriter()
    writer.add_blank_page(width=width, height=height)
    return _write(writer)


def build_mixed_pdf() -> bytes:
    """Three pages: dense real text, a blank/no-text-layer page, dense real
    text again — the "only some pages need OCR" case."""
    writer = PdfWriter()
    page1 = writer.add_blank_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    _attach_text(page1, _DENSE_TEXT)
    writer.add_blank_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)  # page 2: no /Contents
    page3 = writer.add_blank_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    _attach_text(page3, _DENSE_TEXT)
    return _write(writer)


def build_encrypted_pdf(password: str = "s3cret") -> bytes:
    """An encrypted PDF (RC4-128 — needs no extra crypto dependency).
    `PdfReader.is_encrypted` is `True`; `.pages` raises if accessed without
    decrypting first, which this project's pipeline must never attempt."""
    writer = PdfWriter()
    page = writer.add_blank_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    _attach_text(page, _DENSE_TEXT)
    writer.encrypt(user_password=password, algorithm="RC4-128")
    return _write(writer)


def build_decompression_bomb_pdf(*, repeats: int = 400_000) -> bytes:
    """A few tens of KB on disk whose content stream decompresses into
    hundreds of thousands of text-showing operators — small on disk, very
    slow for a pure-Python content-stream tokenizer to walk (verified:
    ~11s wall time for 400_000 repeats on this project's dev host; see
    `docs/handoff/019-pdf-text-extraction.md`)."""
    writer = PdfWriter()
    page = writer.add_blank_page(width=_LETTER_WIDTH, height=_LETTER_HEIGHT)
    _attach_font_resource(page)
    operator = b"BT /F1 12 Tf (A) Tj ET\n"
    _attach_raw_content(page, operator * repeats)
    return _write(writer)


def build_garbled_text_pdf() -> bytes:
    """Dense but mostly replacement/control characters — simulates the
    known `pypdf` weak spot on non-standard glyph encodings that
    `docs/adr/0008-permissive-pdf-extraction.md` documents honestly."""
    garbled = ("\ufffd" * 40 + "\x0b\x0c\x0e" * 10) * 4
    return build_text_pdf(garbled)


def build_sparse_but_real_pdf() -> bytes:
    """A short, legitimate line of text on a normal page. Must NOT be
    routed to OCR: sparse-but-real text is exactly what the density
    threshold is calibrated to leave alone."""
    return build_text_pdf("Factura 001, suma 50 RON.")


def build_numeric_only_pdf() -> bytes:
    """A page containing only numbers/amounts, no natural-language words —
    a known, documented limitation of the word-ratio signal (see the
    handoff's "Known limitations" section): this scores low and is routed
    to OCR even though the extraction itself may be entirely correct."""
    return build_text_pdf("1234,56 78901,23 456,00 " * 40)


def _attach_text(page: PageObject, text: str) -> None:
    _attach_font_resource(page)
    escaped = text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
    lines = [escaped[i : i + 80] for i in range(0, len(escaped), 80)] or [""]
    operators = ["BT", "/F1 12 Tf", "72 720 Td", "14 TL"]
    for i, line in enumerate(lines):
        prefix = "" if i == 0 else "T* "
        operators.append(f"{prefix}({line}) Tj")
    operators.append("ET")
    content = ("\n".join(operators) + "\n").encode("latin-1", errors="replace")
    _attach_raw_content(page, content)


def _attach_font_resource(page: PageObject) -> None:
    font = DictionaryObject()
    font[NameObject("/Type")] = NameObject("/Font")
    font[NameObject("/Subtype")] = NameObject("/Type1")
    font[NameObject("/BaseFont")] = NameObject("/Helvetica")
    resources = DictionaryObject()
    font_dict = DictionaryObject()
    font_dict[NameObject("/F1")] = font
    resources[NameObject("/Font")] = font_dict
    page[NameObject("/Resources")] = resources


def _attach_raw_content(page: PageObject, data: bytes) -> None:
    stream = DecodedStreamObject()
    stream.set_data(data)
    page.replace_contents(stream.flate_encode())


def _write(writer: PdfWriter) -> bytes:
    buffer = io.BytesIO()
    writer.write(buffer)
    return buffer.getvalue()
