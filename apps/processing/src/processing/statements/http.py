"""`POST /v1/statements/parse` — a bank statement file, read into rows.

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
from processing.statements.parse import describe_columns, parse_statement
from processing.statements.schemas import ColumnPayload, ParseStatementResponse

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

    Runs in a thread rather than on the event loop: every reader here is synchronous and a large
    workbook takes seconds, which would otherwise block every other request in the service.
    """
    content = await file.read()
    settings = StatementParseSettings()

    try:
        statement = await run_in_threadpool(parse_statement, content, sheet, settings)
    except StatementParseError as exc:
        status_code, error_code = _ERROR_STATUS.get(type(exc), _UNKNOWN_ERROR_STATUS)
        logger.info(
            "statement_parse_rejected",
            extra={"event": "statement_parse_rejected", "error_code": error_code},
        )

        return JSONResponse(
            status_code=status_code,
            content=ErrorResponse(error=error_code, message=str(exc)).model_dump(),
        )

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
        row_count=len(statement.rows),
        warnings=statement.warnings,
        labels_by_amount_column=statement.labels_by_amount_column,
    )
