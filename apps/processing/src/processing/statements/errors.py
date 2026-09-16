"""Why a statement file could not be read.

One class per reason a person can act on, because the screen says something different for each:
"export a CSV instead" for a scan, "remove the password" for an encrypted file, "this is not a
statement" for everything else. The messages never contain a cell, a name or a filename — the file
is a person's bank statement, and an error is not a place to leak it.
"""


class StatementParseError(Exception):
    """Base for every refusal this package reports."""


class FileTooLargeError(StatementParseError):
    """Bigger than `STATEMENT_PARSE_MAX_FILE_SIZE_BYTES`."""


class UnsupportedFormatError(StatementParseError):
    """The bytes are not a CSV, an Excel workbook or a PDF."""


class EncryptedFileError(StatementParseError):
    """A password-protected PDF or workbook. Nothing can be read without the password, and asking
    for it would mean holding it."""


class NoTextLayerError(StatementParseError):
    """A scanned or photographed PDF: pixels, no text.

    Deliberately its own error rather than "no table found": the remedy is completely different —
    the person exports a CSV from their internet banking instead of a scan, and no amount of
    retrying this file will help.
    """


class NoTableError(StatementParseError):
    """Text, but nothing row-shaped in it."""


class TooManyRowsError(StatementParseError):
    """More rows than `STATEMENT_PARSE_MAX_ROWS`."""


class TooManyPagesError(StatementParseError):
    """More pages than `STATEMENT_PARSE_MAX_PDF_PAGES`."""
