"""Reading bank statement files (CSV, XLSX, XLS, PDF) into rows of text cells.

Nothing here interprets a statement: which column is the date, what a row means and whether it is a
duplicate are all answered on the Laravel side, by a person looking at a preview.
"""

from processing.statements.errors import (
    EncryptedFileError,
    FileTooLargeError,
    NoTableError,
    NoTextLayerError,
    StatementParseError,
    TooManyPagesError,
    TooManyRowsError,
    UnsupportedFormatError,
)
from processing.statements.parse import (
    ParsedStatement,
    describe_columns,
    detect_format,
    parse_statement,
)

__all__ = [
    "EncryptedFileError",
    "FileTooLargeError",
    "NoTableError",
    "NoTextLayerError",
    "ParsedStatement",
    "StatementParseError",
    "TooManyPagesError",
    "TooManyRowsError",
    "UnsupportedFormatError",
    "describe_columns",
    "detect_format",
    "parse_statement",
]
