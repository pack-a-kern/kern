"""Reading a statement in a disposable process — the decompression-bomb defence for statements.

Every hostile case here is a real file, not a stub: the point is that what used to exhaust the
service's own memory now fails alone, quickly, as a refusal a person can act on.
"""

import io
import time
import zipfile

import pytest

from processing.statements.config import StatementParseSettings
from processing.statements.errors import FileTooLargeError, TooManyRowsError, UnsupportedFormatError
from processing.statements.isolated import check_statement_isolated, parse_statement_isolated
from processing.statements.parse import parse_statement


def _xlsx_bomb(empty_rows: int) -> bytes:
    """A real workbook whose one sheet is `empty_rows` empty rows: tiny packed, enormous read."""
    import openpyxl

    buffer = io.BytesIO()
    openpyxl.Workbook().save(buffer)

    source = zipfile.ZipFile(io.BytesIO(buffer.getvalue()))
    out = io.BytesIO()

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as bomb:
        for item in source.infolist():
            data = source.read(item.filename)

            if item.filename == "xl/worksheets/sheet1.xml":
                data = (
                    b'<?xml version="1.0" encoding="UTF-8"?>'
                    b'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                    b"<sheetData>" + b"<row/>" * empty_rows + b"</sheetData></worksheet>"
                )

            bomb.writestr(item, data)

    return out.getvalue()


def test_reads_an_ordinary_csv_exactly_as_the_reader_itself_does() -> None:
    content = b"Data;Suma;Detalii\n2026-01-05;-12,50;Plata card\n"
    settings = StatementParseSettings()

    assert parse_statement_isolated(content, None, settings) == parse_statement(
        content, None, settings
    )


def test_a_workbook_whose_index_says_it_unpacks_too_far_is_refused_before_it_is_opened() -> None:
    # Twenty million empty rows, a few hundred kilobytes on disk: refused from the zip's own index,
    # in the service's process, without a worker ever starting.
    bomb = _xlsx_bomb(20_000_000)
    assert len(bomb) < 1_000_000

    started = time.perf_counter()

    with pytest.raises(FileTooLargeError):
        parse_statement(bomb, None, StatementParseSettings())

    assert time.perf_counter() - started < 2


def test_a_workbook_that_exhausts_its_worker_fails_alone_as_too_large() -> None:
    # Five million rows: under the unpacked ceiling, yet opening it alone cost openpyxl some 400 MB,
    # and it used to do so in the service's own process. Here the worker hits its memory ceiling and
    # ends; the refusal is the only thing that reaches the service.
    bomb = _xlsx_bomb(5_000_000)
    settings = StatementParseSettings(max_worker_memory_bytes=268_435_456)  # 256 MiB

    started = time.perf_counter()

    with pytest.raises(FileTooLargeError):
        parse_statement_isolated(bomb, None, settings)

    assert time.perf_counter() - started < 30


def test_a_file_that_outlasts_its_budget_is_refused_as_too_large() -> None:
    content = b"Data;Suma\n2026-01-05;-12,50\n"
    settings = StatementParseSettings(parse_timeout_seconds=0.001)

    with pytest.raises(FileTooLargeError):
        parse_statement_isolated(content, None, settings)


def test_a_refusal_raised_in_the_worker_arrives_as_itself() -> None:
    # The screen says something different for each refusal; crossing a process must not flatten
    # them into one.
    with pytest.raises(UnsupportedFormatError):
        parse_statement_isolated(b"\x00\x01 not a statement", None, StatementParseSettings())


def test_checks_a_file_in_its_own_process_too() -> None:
    assert check_statement_isolated(b"Data;Suma\n", StatementParseSettings()) == "CSV"


def test_stops_reading_at_one_past_the_row_limit() -> None:
    # In-process, on purpose: this is the reader's own ceiling, which holds even without the worker.
    content = ("Data;Suma\n" + "2026-01-05;-1\n" * 200).encode()

    with pytest.raises(TooManyRowsError):
        parse_statement(content, None, StatementParseSettings(max_rows=50))
