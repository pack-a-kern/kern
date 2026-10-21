"""`POST /v1/statements/parse` — a bank statement file, read into rows — and `/check`, the same
refusals without the reading.

Called by Laravel's statement import over the internal network, authenticated with the same shared
secret as the document endpoints. Like them, it maps each refusal to a closed code the screen can
translate, and says nothing about the content of the file in any log line.
"""

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, File, Form, UploadFile
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool

from processing.documents.http_schemas import ErrorResponse
from processing.internal_auth import require_internal_auth
from processing.statements.config import StatementParseSettings
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
from processing.statements.isolated import check_statement_isolated, parse_statement_isolated
from processing.statements.parse import describe_columns
from processing.statements.schemas import (
    CheckStatementResponse,
    ColumnPayload,
    ParseStatementResponse,
)

logger = logging.getLogger("processing.statements.http")

router = APIRouter()

# One entry per refusal a person can act on. A new subclass without an entry falls through to the
# generic 422 — deliberate, so a missing entry degrades rather than crashes.
_ERROR_STATUS: dict[type[StatementParseError], tuple[int, str]] = {
    FileTooLargeError: (413, "TOO_LARGE"),
    UnsupportedFormatError: (422, "UNSUPPORTED_FORMAT"),
    EncryptedFileError: (422, "ENCRYPTED_FILE"),
    NoTextLayerError: (422, "NO_TEXT_LAYER"),
    NoTableError: (422, "NO_TABLE"),
    TooManyRowsError: (422, "TOO_MANY_ROWS"),
    TooManyPagesError: (422, "TOO_MANY_PAGES"),
}
_UNKNOWN_ERROR_STATUS = (422, "PARSE_FAILED")


def _refusal(exc: StatementParseError, event: str) -> JSONResponse:
    status_code, error_code = _ERROR_STATUS.get(type(exc), _UNKNOWN_ERROR_STATUS)
    logger.info(event, extra={"event": event, "error_code": error_code})

    return JSONResponse(
        status_code=status_code,
        content=ErrorResponse(error=error_code, message=str(exc)).model_dump(),
    )


@router.post(
    "/v1/statements/check",
    response_model=CheckStatementResponse,
    responses={
        401: {"model": ErrorResponse},
        413: {"model": ErrorResponse},
        422: {"model": ErrorResponse},
    },
)
async def check(
    file: Annotated[
        UploadFile,
        File(description="Raw statement bytes: CSV, XLSX, XLS or PDF. Only the bytes decide."),
    ],
    _authenticated: Annotated[None, Depends(require_internal_auth)],
) -> CheckStatementResponse | JSONResponse:
    """Whether the file could be read here, refused with the same codes `parse` would use.

    Asked before a PDF is sent out to be converted: a scan, a password or too many pages is refused
    here and never leaves the installation.
    """
    content = await file.read()

    try:
        file_format = await run_in_threadpool(
            check_statement_isolated, content, StatementParseSettings()
        )
    except StatementParseError as exc:
        return _refusal(exc, "statement_check_rejected")

    return CheckStatementResponse(format=file_format)


@router.post(
    "/v1/statements/parse",
    response_model=ParseStatementResponse,
    responses={
        401: {"model": ErrorResponse},
        413: {"model": ErrorResponse},
        422: {"model": ErrorResponse},
    },
)
async def parse(
    file: Annotated[
        UploadFile,
        File(description="Raw statement bytes: CSV, XLSX, XLS or PDF. Only the bytes decide."),
    ],
    _authenticated: Annotated[None, Depends(require_internal_auth)],
    sheet: Annotated[str | None, Form(description="Which sheet to read, for a workbook.")] = None,
) -> ParseStatementResponse | JSONResponse:
    """Reads the file into rows and describes its columns.

    Read in a disposable process (`processing.statements.isolated`), so a file that unpacks to more
    than its memory allows fails alone; waited on from a thread, so the wait does not block every
    other request in the service.
    """
    content = await file.read()
    settings = StatementParseSettings()

    try:
        statement = await run_in_threadpool(parse_statement_isolated, content, sheet, settings)
    except StatementParseError as exc:
        return _refusal(exc, "statement_parse_rejected")

    columns = describe_columns(statement, settings)

    logger.info(
        "statement_parse_completed",
        extra={
            "event": "statement_parse_completed",
            # Counts and the format only: how many rows a person's statement has is operational,
            # what is in them is not.
            "file_format": statement.file_format,
            "row_count": len(statement.rows),
            "column_count": len(columns),
        },
    )

    return ParseStatementResponse(
        format=statement.file_format,
        sheet=statement.sheet,
        sheets=statement.sheets,
        header_row=statement.header_row,
        columns=[ColumnPayload.model_validate(column) for column in columns],
        rows=statement.rows,
        preamble=statement.preamble,
        row_count=len(statement.rows),
        warnings=statement.warnings,
        labels_by_amount_column=statement.labels_by_amount_column,
    )
