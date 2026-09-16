"""Reading statement files into rows (`processing.statements.parse`).

Every fixture is built in the test rather than committed as a file: a real bank statement is
somebody's account history, and a repository is not the place for one. The shapes below are the ones
Romanian banks actually export — semicolons, `1.234,56`, `DD.MM.YYYY`, Windows-1250 — because those
are what this code exists to read.
"""

import io

import pytest

from processing.statements import (
    EncryptedFileError,
    FileTooLargeError,
    NoTextLayerError,
    UnsupportedFormatError,
    detect_format,
    parse_statement,
)
from processing.statements.config import StatementParseSettings
from processing.statements.parse import describe_columns
from processing.statements.shapes import shape_of


def _pdf_without_text() -> bytes:
    """A PDF with a page and no text on it — what a scan or a photograph looks like to a reader.

    Built here rather than borrowed from the document tests' builders: those live in another test
    package, and a fixture worth three lines is not worth coupling two suites together.
    """
    from pypdf import PdfWriter

    writer = PdfWriter()
    writer.add_blank_page(width=595, height=842)
    buffer = io.BytesIO()
    writer.write(buffer)

    return buffer.getvalue()


_CSV = (
    "Data;Detalii;Debit;Credit\r\n"
    "05.03.2026;PLATA CARD KAUFLAND;123,45;\r\n"
    "06.03.2026;SALARIU MARTIE;;7.500,00\r\n"
    "07.03.2026;TRANSFER POPESCU, ION;1.000,00;\r\n"
)


def _xlsx(rows: list[list[object]], sheet_name: str = "Extras") -> bytes:
    import openpyxl

    workbook = openpyxl.Workbook()
    worksheet = workbook.active
    assert worksheet is not None
    worksheet.title = sheet_name

    for row in rows:
        worksheet.append(row)

    buffer = io.BytesIO()
    workbook.save(buffer)

    return buffer.getvalue()


# --- What the file is, from its bytes ---


def test_detects_each_format_from_the_bytes_alone() -> None:
    assert detect_format(_CSV.encode()) == "CSV"
    assert detect_format(_xlsx([["Data", "Suma"], ["05.03.2026", "10"]])) == "XLSX"
    assert detect_format(_pdf_without_text()) == "PDF"


def test_refuses_an_office_file_that_is_not_a_spreadsheet() -> None:
    # A .docx is a zip with the same signature and the same `[Content_Types].xml` as a workbook;
    # only what is inside tells them apart.
    import zipfile

    buffer = io.BytesIO()

    with zipfile.ZipFile(buffer, "w") as archive:
        archive.writestr("[Content_Types].xml", "<Types/>")
        archive.writestr("word/document.xml", "<w:document/>")

    with pytest.raises(UnsupportedFormatError):
        detect_format(buffer.getvalue())


def test_refuses_bytes_that_are_not_a_statement_at_all() -> None:
    with pytest.raises(UnsupportedFormatError):
        detect_format(b"\x00\x01\x02\x03binary rubbish")


# --- CSV ---


def test_reads_a_romanian_csv_with_semicolons_and_a_quoted_comma() -> None:
    statement = parse_statement(_CSV.encode())

    assert statement.file_format == "CSV"
    assert statement.headers == ["Data", "Detalii", "Debit", "Credit"]
    assert statement.header_row == 0
    assert len(statement.rows) == 3
    # The comma inside "POPESCU, ION" is part of the field, not a column boundary.
    assert statement.rows[2][1] == "TRANSFER POPESCU, ION"


def test_reads_a_csv_written_in_windows_1250_with_a_bom() -> None:
    # Ş with a cedilla, not Ș with a comma below: Windows-1250 has the first and not the second,
    # which is exactly why files written by older Romanian software carry it.
    text = "Data;Detalii\r\n05.03.2026;Plată către Ştefan\r\n"
    statement = parse_statement(text.encode("cp1250"))

    assert "tefan" in statement.rows[0][1]


def test_skips_the_preamble_a_statement_prints_above_its_table() -> None:
    text = (
        "Extras de cont\r\n"
        "Perioada: 01.03.2026 - 31.03.2026\r\n"
        "Data;Detalii;Suma\r\n"
        "05.03.2026;PLATA CARD;-123,45\r\n"
        "06.03.2026;INCASARE;7.500,00\r\n"
    )
    statement = parse_statement(text.encode())

    assert statement.headers == ["Data", "Detalii", "Suma"]
    assert len(statement.rows) == 2


# --- Excel ---


def test_reads_an_xlsx_and_names_its_sheets() -> None:
    content = _xlsx(
        [
            ["Data", "Detalii", "Suma"],
            ["05.03.2026", "PLATA CARD", -123.45],
            ["06.03.2026", "INCASARE", 7500],
        ]
    )
    statement = parse_statement(content)

    assert statement.file_format == "XLSX"
    assert statement.sheet == "Extras"
    assert statement.sheets == ["Extras"]
    # A whole number arrives from Excel as a float; "7500.0" in an amount column would read as a
    # decimal nobody typed.
    assert statement.rows[1][2] == "7500"


def test_reads_the_sheet_asked_for_rather_than_the_first() -> None:
    import openpyxl

    workbook = openpyxl.Workbook()
    first = workbook.active
    assert first is not None
    first.title = "Sumar"
    first.append(["Total", "9.999"])
    second = workbook.create_sheet("Tranzactii")
    second.append(["Data", "Suma"])
    second.append(["05.03.2026", "10,00"])

    buffer = io.BytesIO()
    workbook.save(buffer)

    statement = parse_statement(buffer.getvalue(), sheet="Tranzactii")

    assert statement.sheet == "Tranzactii"
    assert statement.headers == ["Data", "Suma"]


# --- PDF ---


def test_refuses_a_scanned_pdf_with_the_reason_that_names_the_remedy() -> None:
    # No text layer at all: the person has to export a CSV instead, and no retry of this file helps.
    with pytest.raises(NoTextLayerError):
        parse_statement(_pdf_without_text())


# --- Limits ---


def test_refuses_a_file_over_the_size_ceiling() -> None:
    settings = StatementParseSettings(max_file_size_bytes=16)

    with pytest.raises(FileTooLargeError):
        parse_statement(_CSV.encode(), settings=settings)


def test_refuses_more_rows_than_a_statement_can_hold() -> None:
    from processing.statements.errors import TooManyRowsError

    rows = "Data;Suma\r\n" + "".join(f"0{index % 9 + 1}.03.2026;1,00\r\n" for index in range(50))

    with pytest.raises(TooManyRowsError):
        parse_statement(rows.encode(), settings=StatementParseSettings(max_rows=10))


def test_an_encrypted_error_is_its_own_refusal() -> None:
    # Constructed directly: producing a genuinely encrypted workbook needs a writer this service
    # does not have, and the mapping from this error to its code is what matters to a caller.
    assert isinstance(EncryptedFileError("x"), Exception)


# --- Column shapes: what leaves this service instead of the values ---


def test_describes_columns_without_any_value_in_them() -> None:
    statement = parse_statement(_CSV.encode())
    columns = describe_columns(statement, StatementParseSettings())

    assert [column["header"] for column in columns] == ["Data", "Detalii", "Debit", "Credit"]
    assert columns[0]["shape"] == "date DD.MM.YYYY"
    assert str(columns[1]["shape"]).startswith("text ~")
    assert "number" in str(columns[2]["shape"])

    # Nothing in the description repeats a cell.
    described = " ".join(f"{column['header']} {column['shape']}" for column in columns)
    assert "KAUFLAND" not in described
    assert "POPESCU" not in described


def test_shape_reads_the_separators_a_column_writes() -> None:
    assert shape_of(["1.234,56", "7.500,00"]) == "number 1.234,56"
    assert shape_of(["1,234.56", "7,500.00"]) == "number 1,234.56"
    assert shape_of(["-123,45"]) == "number -1234,56"
    assert shape_of(["RON", "EUR"]) == "currency code"
    assert shape_of(["", "  "]) == "empty"


def test_a_column_that_is_only_mostly_one_thing_says_so() -> None:
    assert shape_of(["05.03.2026", "06.03.2026", "n/a"]).endswith("(mostly)")
