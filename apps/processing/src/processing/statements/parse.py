"""Reading a bank statement file into a grid of text cells.

## Why this lives in the processing plane

Three reasons, all of them about where the code runs rather than what language it is in:

- **The bytes are untrusted.** A statement arrives from outside, and every reader here — zip, XML,
  PDF — has a history of being attacked through malformed input. This container holds no database
  credentials (`BLUEPRINT.md` §3.3), so the worst case is bounded.
- **The readers already exist here.** PDFs are opened in this service today; spreadsheets need
  libraries the PHP image has no extension for (`zip` is not built into it).
- **Nothing is decided here.** The file becomes rows and columns. Which column is the date, what the
  decimal separator is, whether a row is a duplicate — all of that is answered later, by a person
  looking at a preview.

## What it does not do

No values are converted. "1.234,56" stays that string, because turning it into a number means
choosing a decimal separator, and that choice belongs to the column matching a person confirms.
"""

import collections
import csv
import io
import itertools
import re
import zipfile
from dataclasses import dataclass, field
from typing import TYPE_CHECKING

from processing.statements.config import StatementParseSettings
from processing.statements.errors import (
    EncryptedFileError,
    FileTooLargeError,
    NoTableError,
    NoTextLayerError,
    TooManyPagesError,
    TooManyRowsError,
    UnsupportedFormatError,
)
from processing.statements.shapes import shape_of

if TYPE_CHECKING:
    from pdfplumber.page import Page
    from pdfplumber.pdf import PDF

_PDF_SIGNATURE = b"%PDF-"
_ZIP_SIGNATURE = b"PK\x03\x04"
_OLE_SIGNATURE = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"
_PDF_SCAN_WINDOW = 1024
# Delimiters a bank exports with. The semicolon leads in Romania, where the comma is the decimal
# separator and Excel writes CSV accordingly.
_DELIMITERS = ";,\t|"

# A date at the start of a line is what opens a transaction, whether its month is written in
# digits or as a word. Used only to group label statistics — which column *means* the date is
# decided later, by a person.
_DATE_LIKE = re.compile(
    r"^\d{1,2}\s+[^\W\d_]{3,12}\.?\s+\d{4}$"
    r"|^\d{1,2}[./-]\d{1,2}[./-]\d{2,4}"
    r"|^\d{4}[./-]\d{1,2}[./-]\d{1,2}",
    re.UNICODE,
)
# An amount, with the currency some banks write beside it in the same cell.
#
# Used only to find which column a block's amount sat in, so the labels beneath it can be grouped by
# that column — never to read a value. Without the currency, `75,000.00 USD` was not an amount here,
# so the block it opened was skipped and every label under it went uncounted: a statement whose
# bank writes the currency inline offered nothing on the mapping screen, while the same statement
# imported all of its transactions correctly, because that path never consults this.
_NUMERIC_CELL = re.compile(
    r"^-?\d{1,3}([.,\s]\d{3})*([.,]\d{1,2})?(\s*[A-Za-z]{3})?$"
    r"|^-?\d+[.,]\d{1,2}(\s*[A-Za-z]{3})?$"
)
_LABELLED_CELL = re.compile(r"^\s*([^:]{2,45}?)\s*:\s*(.*)$", re.UNICODE)


@dataclass(frozen=True)
class ParsedStatement:
    """A file read into rows, with the header row identified."""

    file_format: str
    rows: list[list[str]]
    headers: list[str]
    header_row: int | None
    sheet: str | None = None
    sheets: list[str] = field(default_factory=list[str])
    warnings: list[str] = field(default_factory=list[str])
    # For a file whose transactions span several lines: which labels appear beneath transactions
    # whose amount sat in each column. This is what tells a reader that `Tranzactie la` belongs to
    # money going out and `Ordonator` to money coming in, without anything here deciding what the
    # columns mean.
    # The lines above the header — the bank's name, the account, the period — each as its filled
    # cells. Kept apart from the rows because they are not transactions, and returned because the
    # statement's own IBAN, which says which bank issued it, is written there.
    preamble: list[list[str]] = field(default_factory=list[list[str]])
    labels_by_amount_column: list[dict[str, object]] = field(
        default_factory=list[dict[str, object]]
    )


def detect_format(content: bytes) -> str:
    """What the file is, from its own bytes — the declared name and content type are ignored.

    An OOXML package (`.xlsx`) and a Word file share a signature and a `[Content_Types].xml` entry,
    so this looks inside the zip for a workbook part rather than trusting the container.
    """
    if _PDF_SIGNATURE in content[:_PDF_SCAN_WINDOW]:
        return "PDF"

    if content.startswith(_ZIP_SIGNATURE):
        try:
            with zipfile.ZipFile(io.BytesIO(content)) as archive:
                names = archive.namelist()
        except zipfile.BadZipFile as exc:
            raise UnsupportedFormatError("The file is not a readable workbook.") from exc

        if any(name.startswith("xl/") for name in names):
            return "XLSX"

        raise UnsupportedFormatError("This is an Office file, but not a spreadsheet.")

    if content.startswith(_OLE_SIGNATURE):
        return "XLS"

    if _looks_like_text(content):
        return "CSV"

    raise UnsupportedFormatError("The file is not a CSV, a spreadsheet or a PDF.")


def _looks_like_text(content: bytes) -> bool:
    """No NUL bytes in the first kilobyte, and decodable as text.

    Deliberately the same test the upload sniffer applies on the PHP side: a binary container always
    carries a NUL early, and text never does.
    """
    window = content[:1024]

    if window == b"" or b"\0" in window:
        return False

    return _decode(content) is not None


def _decode(content: bytes) -> str | None:
    """The file as text, trying what a Romanian bank actually exports.

    `charset-normalizer` decides, with a short list to fall back on: UTF-8 first, then the two
    Windows code pages Excel writes here, then Latin-1, which decodes any byte and is therefore the
    last resort rather than a real answer.
    """
    from charset_normalizer import from_bytes

    best = from_bytes(content).best()

    if best is not None:
        return str(best)

    for encoding in ("utf-8-sig", "utf-8", "cp1250", "cp1252", "latin-1"):
        try:
            return content.decode(encoding)
        except UnicodeDecodeError:
            continue

    return None


def _read_csv(content: bytes, settings: StatementParseSettings) -> ParsedStatement:
    text = _decode(content)

    if text is None:
        raise UnsupportedFormatError("The file could not be read as text.")

    text = text.lstrip("﻿")

    try:
        dialect = csv.Sniffer().sniff(text[:8192], delimiters=_DELIMITERS)
        delimiter = dialect.delimiter
    except csv.Error:
        # Sniffing fails on a file with one column, or an unusual quoting style. The delimiter that
        # appears most often on the first line is a better guess than giving up.
        first = text.splitlines()[0] if text.splitlines() else ""
        delimiter = max(_DELIMITERS, key=first.count)

    rows = [
        [cell.strip() for cell in row]
        for row in itertools.islice(
            csv.reader(io.StringIO(text), delimiter=delimiter), _row_ceiling(settings)
        )
    ]

    return _assemble("CSV", rows, settings)


def _read_xlsx(
    content: bytes, sheet: str | None, settings: StatementParseSettings
) -> ParsedStatement:
    import openpyxl

    _refuse_if_it_unpacks_too_far(content, settings)

    try:
        # `read_only` streams rather than building the whole sheet; `data_only` takes the value a
        # formula last produced rather than the formula, which is what a statement's reader wants.
        workbook = openpyxl.load_workbook(io.BytesIO(content), read_only=True, data_only=True)
    except zipfile.BadZipFile as exc:
        raise UnsupportedFormatError("The workbook could not be opened.") from exc
    except Exception as exc:  # openpyxl raises several unrelated types for a broken file
        if "password" in str(exc).lower() or "encrypted" in str(exc).lower():
            raise EncryptedFileError("The workbook is password-protected.") from exc

        raise UnsupportedFormatError("The workbook could not be opened.") from exc

    try:
        sheets = list(workbook.sheetnames)
        chosen = sheet if sheet in sheets else sheets[0]
        worksheet = workbook[chosen]

        rows = [
            _trim_trailing_empties([_cell_text(value) for value in row])
            # Bounded at the source, like the other readers, rather than read whole and counted.
            for row in worksheet.iter_rows(
                values_only=True, max_row=_row_ceiling(settings), max_col=settings.max_columns
            )
        ]
    finally:
        workbook.close()

    parsed = _assemble("XLSX", rows, settings)

    return ParsedStatement(
        file_format=parsed.file_format,
        rows=parsed.rows,
        headers=parsed.headers,
        header_row=parsed.header_row,
        preamble=parsed.preamble,
        sheet=chosen,
        sheets=sheets,
        warnings=parsed.warnings,
        labels_by_amount_column=parsed.labels_by_amount_column,
    )


def _read_xls(
    content: bytes, sheet: str | None, settings: StatementParseSettings
) -> ParsedStatement:
    import xlrd

    try:
        workbook = xlrd.open_workbook(file_contents=content)
    except Exception as exc:
        message = str(exc).lower()

        if "encrypt" in message or "password" in message:
            raise EncryptedFileError("The workbook is password-protected.") from exc

        raise UnsupportedFormatError("The workbook could not be opened.") from exc

    sheets = list(workbook.sheet_names())
    chosen = sheet if sheet in sheets else sheets[0]
    worksheet = workbook.sheet_by_name(chosen)

    rows: list[list[str]] = []

    for index in range(min(worksheet.nrows, _row_ceiling(settings))):
        values = worksheet.row_values(index, end_colx=settings.max_columns)
        types = worksheet.row_types(index, end_colx=settings.max_columns)
        row: list[str] = []

        for value, cell_type in zip(values, types, strict=False):
            # An .xls date is a number with a flag beside it; without the flag every date in the
            # file would arrive as "45678", which nothing downstream could recognise.
            if cell_type == xlrd.XL_CELL_DATE and isinstance(value, (int, float)):
                stamp = xlrd.xldate_as_datetime(float(value), workbook.datemode)
                row.append(
                    stamp.date().isoformat() if stamp.time().hour == 0 else stamp.isoformat(" ")
                )
            else:
                row.append(_cell_text(value))

        rows.append(row)

    parsed = _assemble("XLS", rows, settings)

    return ParsedStatement(
        file_format=parsed.file_format,
        rows=parsed.rows,
        headers=parsed.headers,
        header_row=parsed.header_row,
        preamble=parsed.preamble,
        sheet=chosen,
        sheets=sheets,
        warnings=parsed.warnings,
        labels_by_amount_column=parsed.labels_by_amount_column,
    )


def _open_pdf(content: bytes, settings: StatementParseSettings) -> PDF:
    """The PDF, opened, once it has passed the checks every reading of it needs.

    Shared by the reader and by `check_statement`, so a PDF refused before it is sent to be
    converted is refused for exactly the reasons, and with exactly the codes, it would be here.
    """
    import pdfplumber

    try:
        pdf = pdfplumber.open(io.BytesIO(content))
    except Exception as exc:
        if "password" in str(exc).lower() or "encrypt" in str(exc).lower():
            raise EncryptedFileError("The PDF is password-protected.") from exc

        raise UnsupportedFormatError("The PDF could not be opened.") from exc

    if len(pdf.pages) > settings.max_pdf_pages:
        pdf.close()

        raise TooManyPagesError(f"The PDF has more than {settings.max_pdf_pages} pages.")

    return pdf


def _page_has_text(page: Page) -> bool:
    return (page.extract_text() or "").strip() != ""


def _read_pdf(content: bytes, settings: StatementParseSettings) -> ParsedStatement:
    warnings: list[str] = []
    pdf = _open_pdf(content, settings)

    try:
        rows: list[list[str]] = []
        header: list[str] | None = None
        had_text = False

        for page in pdf.pages:
            if _page_has_text(page):
                had_text = True

            tables = page.extract_tables()

            if not tables:
                # Most bank PDFs draw no ruling lines at all: the columns are held apart by
                # position alone. pdfplumber's default strategy looks for lines, so without this
                # fallback every unruled statement — which is most of them — would be "no table".
                tables = page.extract_tables(
                    {"vertical_strategy": "text", "horizontal_strategy": "text"}
                )

            if not tables:
                warnings.append("PAGES_WITHOUT_TABLE")

                continue

            for table in tables:
                for raw in table:
                    row = [_cell_text(cell) for cell in raw]

                    if all(cell == "" for cell in row):
                        continue

                    # A statement repeats its column headings on every page. The first one is the
                    # header; the rest are the same line again and would otherwise become rows.
                    if header is None:
                        header = row
                    elif row == header:
                        continue

                    rows.append(row)
    finally:
        pdf.close()

    if not had_text:
        raise NoTextLayerError("The PDF holds no text — it is a scan or a photograph.")

    if not rows:
        raise NoTableError("No rows could be found in the PDF.")

    parsed = _assemble("PDF", rows, settings)

    return ParsedStatement(
        file_format=parsed.file_format,
        rows=parsed.rows,
        headers=parsed.headers,
        header_row=parsed.header_row,
        preamble=parsed.preamble,
        warnings=sorted(set(parsed.warnings + warnings)),
        labels_by_amount_column=parsed.labels_by_amount_column,
    )


def _refuse_if_it_unpacks_too_far(content: bytes, settings: StatementParseSettings) -> None:
    """Refuses a workbook whose zip index says it unpacks past the ceiling, before opening it.

    Needed even with the row limit: `openpyxl.load_workbook` itself, in read-only mode, spent nine
    seconds and some 400 MB on a 48 KB workbook whose sheet was five million empty rows — before a
    single row was asked for. The index can lie, but `zipfile` never reads past the size it
    declares, so a lying entry arrives truncated and fails to parse rather than unpacking further.
    """
    try:
        unpacked = sum(entry.file_size for entry in zipfile.ZipFile(io.BytesIO(content)).infolist())
    except zipfile.BadZipFile as exc:
        raise UnsupportedFormatError("The workbook could not be opened.") from exc

    if unpacked > settings.max_unpacked_bytes:
        raise FileTooLargeError("The file is too large to read.")


def _row_ceiling(settings: StatementParseSettings) -> int:
    """How many rows a reader takes before it stops: one past the limit.

    One past, so `_assemble` still sees that there were too many and refuses the file as it always
    did — but having read only that far. Reading every row first and counting afterwards is how a
    sheet of millions of empty rows filled the memory before the limit was ever checked.
    """
    return settings.max_rows + 1


def _trim_trailing_empties(row: list[str]) -> list[str]:
    """Drops the empty cells a spreadsheet pads its rows out to.

    A sheet declares a width, and every row is handed back at that width however few cells were
    typed. Left in place they become columns the file does not have — a two-column statement whose
    header row is two names and thirty-eight blanks.
    """
    while row and row[-1] == "":
        row.pop()

    return row


def _cell_text(value: object) -> str:
    """A cell as text, with the two conversions a spreadsheet forces.

    A whole number arrives from Excel as a float — `2026.0` for a year — and the trailing `.0` would
    make a column of years look like decimals. `None` is an empty cell, not the word "None".
    """
    if value is None:
        return ""

    if isinstance(value, float) and value.is_integer():
        return str(int(value))

    return str(value).strip()


def _assemble(
    file_format: str, raw_rows: list[list[str]], settings: StatementParseSettings
) -> ParsedStatement:
    """Drop the blank rows, find the header row, and hand back the data rows.

    The header is the first row with at least two filled cells that is within one cell of the
    widest row in the file. A statement usually opens with the account holder, the period and an
    address, each on a line of its own — one cell each — while a table's header names every column.
    Taking the first non-empty line as the header named every column after an address, and for a
    file whose preamble has no delimiter at all, collapsed the whole statement into a single column.

    The widest row rather than the most common width, deliberately: in a statement whose
    transactions are followed by detail lines, the one- and two-cell detail lines are the most
    common rows, and a two-cell preamble line would pass for a header against them.
    """
    rows = [row for row in raw_rows if any(cell.strip() != "" for cell in row)]

    if not rows:
        raise NoTableError("The file holds no rows.")

    if len(rows) > settings.max_rows:
        raise TooManyRowsError(f"The file holds more than {settings.max_rows} rows.")

    width = max(len(row) for row in rows)

    if width > settings.max_columns:
        raise NoTableError(f"The file has more than {settings.max_columns} columns.")

    padded = [row + [""] * (width - len(row)) for row in rows]

    widest = max((sum(1 for cell in row if cell.strip() != "") for row in padded), default=0)
    header_row: int | None = None

    for index, row in enumerate(padded[:-1]):
        filled = sum(1 for cell in row if cell.strip() != "")

        if filled >= 2 and filled >= widest - 1:
            header_row = index

            break

    headers = padded[header_row] if header_row is not None else [""] * width
    data = padded[header_row + 1 :] if header_row is not None else padded

    above = padded[: min(header_row, settings.max_preamble_rows)] if header_row is not None else []

    return ParsedStatement(
        file_format=file_format,
        rows=data,
        headers=headers,
        header_row=header_row,
        preamble=[[cell.strip() for cell in row if cell.strip() != ""] for row in above],
        labels_by_amount_column=_labels_by_amount_column(data),
    )


def _labels_by_amount_column(rows: list[list[str]]) -> list[dict[str, object]]:
    """Which labels appear beneath transactions whose amount sat in each column.

    A transaction opens on a line whose first cell is a date; the lines under it, until the next
    such line, are its details. Grouping their labels by which column carried that transaction's
    number is what separates "money out" details from "money in" details — without this code
    knowing, or needing to know, which column means what.

    Empty for a file with one transaction per line: nothing sits beneath anything.
    """
    blocks: list[list[list[str]]] = []
    current: list[list[str]] | None = None

    for row in rows:
        if row and _DATE_LIKE.match(row[0].strip()):
            current = [row]
            blocks.append(current)
        elif current is not None:
            current.append(row)

    counts: dict[int, collections.Counter[str]] = {}
    totals: collections.Counter[int] = collections.Counter()

    for block in blocks:
        numeric = [
            index for index, cell in enumerate(block[0]) if _NUMERIC_CELL.match(cell.strip())
        ]

        if not numeric:
            continue

        # The first numeric cell: a running balance is written after the amount, never before it.
        column = numeric[0]
        totals[column] += 1
        counter = counts.setdefault(column, collections.Counter())

        for row in block[1:]:
            for cell in row:
                match = _LABELLED_CELL.match(cell.strip())

                if match:
                    counter[match.group(1)] += 1

    return [
        {
            "amount_column": column,
            "blocks": totals[column],
            "labels": [
                {"label": name, "seen_in_percent": round(100 * count / totals[column])}
                for name, count in counter.most_common(10)
            ],
        }
        for column, counter in sorted(counts.items())
    ]


def describe_columns(
    statement: ParsedStatement, settings: StatementParseSettings
) -> list[dict[str, object]]:
    """Each column as a header, a shape and how often it is filled — never as its values."""
    width = (
        len(statement.headers)
        if statement.headers
        else (len(statement.rows[0]) if statement.rows else 0)
    )
    sample = statement.rows[: settings.sample_rows]
    columns: list[dict[str, object]] = []

    for index in range(width):
        values = [row[index] if index < len(row) else "" for row in sample]
        filled = sum(1 for value in values if value.strip() != "")

        columns.append(
            {
                "index": index,
                "header": _clean_header(
                    statement.headers[index] if index < len(statement.headers) else ""
                ),
                "shape": shape_of(values),
                "filled_ratio": round(filled / len(values), 2) if values else 0.0,
            }
        )

    return columns


def _clean_header(header: str) -> str:
    """One line, collapsed whitespace: a PDF header cell often wraps over two lines."""
    return re.sub(r"\s+", " ", header).strip()


def _checked_format(content: bytes, settings: StatementParseSettings) -> str:
    """The file's format, once its size has been checked — the first step of every reading."""
    if len(content) > settings.max_file_size_bytes:
        raise FileTooLargeError(f"The file is larger than {settings.max_file_size_bytes} bytes.")

    if content == b"":
        raise UnsupportedFormatError("The file is empty.")

    return detect_format(content)


def check_statement(content: bytes, settings: StatementParseSettings | None = None) -> str:
    """Whether a file could be read here, without reading it — and its format.

    Asked before a PDF is sent out to be converted to CSV. The converter would read a scan or a
    photograph too, and a statement import does not accept those; checking here keeps the refusals
    what they have always been — a password, too many pages, no text — rather than letting whatever
    the converter happens to accept decide.
    """
    settings = settings or StatementParseSettings()
    file_format = _checked_format(content, settings)

    if file_format != "PDF":
        return file_format

    pdf = _open_pdf(content, settings)

    try:
        if not any(_page_has_text(page) for page in pdf.pages):
            raise NoTextLayerError("The PDF holds no text — it is a scan or a photograph.")
    finally:
        pdf.close()

    return file_format


def parse_statement(
    content: bytes,
    sheet: str | None = None,
    settings: StatementParseSettings | None = None,
) -> ParsedStatement:
    """Read a statement file of any supported format into rows."""
    settings = settings or StatementParseSettings()
    file_format = _checked_format(content, settings)

    if file_format == "CSV":
        return _read_csv(content, settings)

    if file_format == "XLSX":
        return _read_xlsx(content, sheet, settings)

    if file_format == "XLS":
        return _read_xls(content, sheet, settings)

    return _read_pdf(content, settings)
