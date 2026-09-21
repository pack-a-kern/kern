"""The contract `POST /v1/statements/parse` answers with.

Cells are text exactly as the file holds them: converting "1.234,56" to a number here would be
deciding what the decimal separator is, which is the question the column matching answers later. The
service reads the file; it does not interpret the statement.
"""

from pydantic import BaseModel, ConfigDict, Field


class ColumnPayload(BaseModel):
    """One column, described rather than shown.

    `header` is the text in the header row — which banks do print — and `shape` is what the values
    look like. Together they are everything the column-matching step is given; the values themselves
    never leave this service.
    """

    model_config = ConfigDict(frozen=True, extra="forbid")

    index: int = Field(ge=0)
    header: str
    shape: str
    filled_ratio: float = Field(ge=0, le=1)


class ParseStatementResponse(BaseModel):
    """A statement file as a grid, with its columns described."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    format: str = Field(description="CSV, XLSX, XLS or PDF — decided by the bytes, never the name.")
    sheet: str | None = Field(default=None, description="Which sheet was read, for a workbook.")
    sheets: list[str] = Field(default_factory=list, description="Every sheet the workbook holds.")
    header_row: int | None = Field(
        default=None, ge=0, description="Index of the row the headers came from."
    )
    columns: list[ColumnPayload]
    rows: list[list[str]] = Field(description="Data rows, headers excluded, cells as text.")
    row_count: int = Field(ge=0)
    warnings: list[str] = Field(
        default_factory=list,
        description="Closed codes for what was odd but not fatal — e.g. PAGES_WITHOUT_TABLE.",
    )
    labels_by_amount_column: list[dict[str, object]] = Field(
        default_factory=list[dict[str, object]],
        description="For multi-line transactions: which `Label:` prefixes appear beneath "
        "transactions whose amount sat in each column, and how often. Empty for a file with one "
        "transaction per line.",
    )


class CheckStatementResponse(BaseModel):
    """A statement file that could be read here, and what it is."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    format: str = Field(description="CSV, XLSX, XLS or PDF — decided by the bytes, never the name.")
