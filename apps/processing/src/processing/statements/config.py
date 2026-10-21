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
    max_preamble_rows: int = Field(
        default=20,
        ge=0,
        description="Lines above the header handed back as the preamble. A statement's heading is "
        "a handful of lines; a file whose header was found far down has a table above it, not one.",
    )
    sample_rows: int = Field(
        default=200,
        gt=0,
        description="How many rows a column's shape is measured over. The shape is a summary for "
        "the model that matches columns, and reading every row to build it buys nothing.",
    )
    max_unpacked_bytes: int = Field(
        default=52_428_800,  # 50 MiB
        gt=0,
        description="Ceiling on what a workbook unpacks to, read from the zip's own index before "
        "anything is opened. A statement of the most rows this accepts unpacks to a few megabytes; "
        "past this, the file is a decompression bomb or not a statement, and is refused at once "
        "instead of costing a worker process its memory and its budget first.",
    )
    parse_timeout_seconds: float = Field(
        default=60.0,
        gt=0,
        description="Wall-clock budget for reading one file in its isolated worker process. "
        "Longer than a document's, because a statement PDF's tables are slower to find than its "
        "text. Exceeding it kills the worker — the decompression-bomb defence "
        "(processing.isolation).",
    )
    max_worker_memory_bytes: int = Field(
        default=536_870_912,  # 512 MiB
        gt=0,
        description="RLIMIT_AS inside the isolated worker, so a file that unpacks to more than "
        "this fails on its own instead of taking the service's memory, and every other request, "
        "with it.",
    )
