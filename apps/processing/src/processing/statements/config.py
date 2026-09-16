"""Limits for reading a bank statement file.

Every value is an engineering threshold rather than a fiscal one, so none carries a source section
— but per `docs/CODE-QUALITY.md` §4 they are named, documented and overridable through
`STATEMENT_PARSE_*` environment variables rather than written at the call site.

The ceilings exist for the same reason the document extractor's do: the bytes arrive from outside,
and a file that claims to be a statement can be a decompression bomb, a spreadsheet with a million
empty rows, or a PDF with a thousand pages.
"""

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class StatementParseSettings(BaseSettings):
    """Loaded from `STATEMENT_PARSE_*`; every field has a safe default so the service runs
    unconfigured in development."""

    model_config = SettingsConfigDict(env_prefix="STATEMENT_PARSE_", extra="forbid", frozen=True)

    max_file_size_bytes: int = Field(
        default=10_485_760,  # 10 MiB
        gt=0,
        description="Hard ceiling on input size, checked before anything parses it.",
    )
    max_rows: int = Field(
        default=5_000,
        gt=0,
        description="Rows a single statement may hold. A personal account's year is a few "
        "thousand; beyond that the file is something other than a statement.",
    )
    max_pdf_pages: int = Field(
        default=60,
        gt=0,
        description="Pages a statement PDF may have, checked before any page is read.",
    )
    max_columns: int = Field(
        default=40,
        gt=0,
        description="Columns a row may have. Wider than any statement, narrow enough that a "
        "malformed file cannot turn into a grid nobody can show.",
    )
    sample_rows: int = Field(
        default=200,
        gt=0,
        description="How many rows a column's shape is measured over. The shape is a summary for "
        "the model that matches columns, and reading every row to build it buys nothing.",
    )
