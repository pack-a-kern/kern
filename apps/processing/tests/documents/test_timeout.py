"""Process-isolated, timeout-bounded parsing — the decompression-bomb
defence. Every test here exercises a genuinely hostile or failing input,
not a happy-path stub.
"""

import time
from multiprocessing.connection import Connection

import pytest
from pdf_builders import (
    build_decompression_bomb_pdf,
    build_encrypted_pdf,
    build_mixed_pdf,
    build_text_pdf,
)

from processing.documents.errors import (
    EncryptedPdfRejectedError,
    ExtractionTimeoutError,
    MalformedPdfError,
    TooManyPagesError,
)

# `_run` is intentionally private; it is imported here to test the
# worker-died -> MalformedPdfError conversion directly, because the only way
# to exercise that branch through the public API would be to actually crash
# a real worker process (e.g. via the kernel OOM-killer), which is not
# something a test suite can trigger deterministically.
from processing.documents.timeout import (
    _run,  # pyright: ignore[reportPrivateUsage]
    parse_and_extract_pages,
)


def test_a_normal_small_pdf_extracts_well_within_the_timeout() -> None:
    pdf = build_text_pdf()

    started = time.perf_counter()
    pages = parse_and_extract_pages(
        pdf, max_pages=200, max_memory_bytes=512_000_000, timeout_seconds=5.0
    )
    elapsed = time.perf_counter() - started

    assert len(pages) == 1
    assert "Factura" in pages[0][2]
    assert elapsed < 3.0  # generous margin over the ~0.1-0.2s measured spawn overhead


def test_a_decompression_bomb_is_killed_by_the_wall_clock_timeout() -> None:
    """A file that is ~20KB on disk but decompresses into hundreds of
    thousands of text-showing operators takes over ten seconds to tokenize
    on this project's dev host — this asserts the timeout aborts it long
    before that, not that parsing merely "didn't crash"."""
    bomb = build_decompression_bomb_pdf(repeats=400_000)

    started = time.perf_counter()
    with pytest.raises(ExtractionTimeoutError):
        parse_and_extract_pages(
            bomb, max_pages=200, max_memory_bytes=512_000_000, timeout_seconds=0.5
        )
    elapsed = time.perf_counter() - started

    assert elapsed < 3.0  # proves it was killed, not that it happened to finish fast


def test_a_decompression_bomb_is_rejected_by_the_worker_memory_ceiling() -> None:
    """Independent defence from the timeout: the same bomb's decompressed
    content stream (~9 MB) exceeds a deliberately tight memory ceiling and
    is rejected by the worker's own `RLIMIT_AS`, fast, before the wall-clock
    timeout would even matter."""
    bomb = build_decompression_bomb_pdf(repeats=400_000)

    started = time.perf_counter()
    with pytest.raises(MalformedPdfError, match="memory limit exceeded"):
        parse_and_extract_pages(
            bomb, max_pages=200, max_memory_bytes=15_000_000, timeout_seconds=15.0
        )
    elapsed = time.perf_counter() - started

    assert elapsed < 3.0  # rejected fast, nowhere near the 15s timeout budget


def test_an_encrypted_pdf_is_reported_without_ever_touching_its_pages() -> None:
    encrypted = build_encrypted_pdf()

    with pytest.raises(EncryptedPdfRejectedError):
        parse_and_extract_pages(
            encrypted, max_pages=200, max_memory_bytes=512_000_000, timeout_seconds=5.0
        )


def test_too_many_pages_is_rejected_before_any_page_text_is_extracted() -> None:
    three_pages = build_mixed_pdf()

    with pytest.raises(TooManyPagesError, match="3"):
        parse_and_extract_pages(
            three_pages, max_pages=2, max_memory_bytes=512_000_000, timeout_seconds=5.0
        )


def test_a_malformed_pdf_is_reported_as_malformed_not_as_a_crash() -> None:
    not_really_a_pdf = b"%PDF-1.4\nthis is not valid PDF content at all"

    with pytest.raises(MalformedPdfError):
        parse_and_extract_pages(
            not_really_a_pdf, max_pages=200, max_memory_bytes=512_000_000, timeout_seconds=5.0
        )


def _die_without_sending(_pdf_bytes: bytes, _unused: int, conn: Connection) -> None:
    """Test-only worker shape: closes the pipe without ever calling
    `conn.send`, simulating a worker process that is killed by something
    outside this codebase's control (e.g. a container OOM-killer)."""
    conn.close()


def test_a_worker_that_dies_without_a_result_is_reported_as_malformed_not_eof() -> None:
    """A worker whose pipe closes with nothing sent must surface as a
    `MalformedPdfError`, never as an unhandled `EOFError`."""
    with pytest.raises(MalformedPdfError, match="terminated unexpectedly"):
        _run(_die_without_sending, (b"", 0), 5.0)
