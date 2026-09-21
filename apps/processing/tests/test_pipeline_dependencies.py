"""Confirms the M3/M7 pipeline dependencies actually import and work on this interpreter.

These wheels have narrow platform/ABI support (see `docs/reference/verified-versions.md`
on paddlepaddle's lack of a cp314 wheel, which is why it is not a dependency).
This test is the regression check that pypdf keeps working on the pinned
Python 3.14 target — it does not exercise any pipeline logic, which is Milestone 3/7 work.

`pypdf` replaced `PyMuPDF` per ADR 0008 (AGPL-3.0-or-later removed; BSD-3-Clause adopted).
"""


def test_pypdf_can_read_a_pdf_and_detect_its_text_layer() -> None:
    """Exercises the exact API surface §14.3's native-text-before-OCR step needs:
    read a PDF, get its pages, call `extract_text()` per page. A freshly created blank
    page has no text layer, so `extract_text()` returning an empty string is the
    correct result here, not a failure — this is a dependency smoke test, not the
    M3/M7 text-layer-detection pipeline itself.
    """
    import io

    from pypdf import PdfReader, PdfWriter

    writer = PdfWriter()
    writer.add_blank_page(width=200, height=200)
    buffer = io.BytesIO()
    writer.write(buffer)
    buffer.seek(0)

    reader = PdfReader(buffer)
    assert len(reader.pages) == 1
    assert reader.pages[0].extract_text() == ""
