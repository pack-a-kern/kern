"""HTTP surface for `apps/processing`'s PDF text-extraction capability
(`docs/handoff/019-pdf-text-extraction.md`'s `extract_document`), exposed
for Laravel's `apps/api` `HttpTextExtractionGateway` to call over the
internal `processing_net` network (`BLUEPRINT.md` §3.3).

See `docs/handoff/024-extraction-endpoint.md` for the exact final contract,
including how it differs from what
`docs/handoff/022-document-pipeline.md` assumed before this endpoint
existed. This module only wires HTTP concerns (auth, request/response
shape, error-code mapping) around `extract_document` — every security
control and quality threshold lives in `processing.documents` and is not
duplicated here.
"""

import logging
from typing import Annotated

from fastapi import APIRouter, Depends, File, UploadFile
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool

from processing.documents import (
    DocumentExtractionError,
    EmptyDocumentError,
    EncryptedPdfRejectedError,
    ExtractionTimeoutError,
    FileTooLargeError,
    InvalidMagicBytesError,
    MalformedPdfError,
    TooManyPagesError,
    extract_document,
)
from processing.documents.http_schemas import ErrorResponse, ExtractTextResponse
from processing.documents.page_count import count_pdf_pages
from processing.internal_auth import require_internal_auth

logger = logging.getLogger("processing.documents.http")

router = APIRouter()

# One entry per `DocumentExtractionError` subclass (`processing.documents.errors`) so Laravel
# can distinguish "this document is bad" (4xx, a specific `error` code) from "the service
# failed" (a connection error/5xx/timeout, handled entirely on the Laravel side — see
# `TextExtractionUnavailableException`). Every subclass that exists today is listed explicitly;
# a new subclass added later without an entry here falls through to the generic 422 below,
# which is a deliberate fail-safe, not a reason to skip adding it properly.
_ERROR_STATUS: dict[type[DocumentExtractionError], tuple[int, str]] = {
    FileTooLargeError: (413, "file_too_large"),
    InvalidMagicBytesError: (422, "invalid_magic_bytes"),
    EncryptedPdfRejectedError: (422, "encrypted_pdf"),
    TooManyPagesError: (422, "too_many_pages"),
    EmptyDocumentError: (422, "empty_document"),
    MalformedPdfError: (422, "malformed_pdf"),
    ExtractionTimeoutError: (422, "extraction_timeout"),
}
_UNKNOWN_ERROR_STATUS = (422, "extraction_failed")


@router.post(
    "/v1/documents/page-count",
    responses={
        401: {"model": ErrorResponse},
        413: {"model": ErrorResponse},
        422: {"model": ErrorResponse},
    },
)
async def page_count(
    file: Annotated[
        UploadFile,
        File(description="Raw PDF bytes. Content-type is ignored; only the bytes decide."),
    ],
    _authenticated: Annotated[None, Depends(require_internal_auth)],
) -> JSONResponse:
    """How many pages a PDF has, so a caller can refuse an over-long one cheaply.

    Deliberately separate from `/extract-text`: walking the page tree answers
    in milliseconds where reading every page costs multiples of that, and the
    whole point of this endpoint is to reject a document *before* anything
    expensive — local or remote — has looked at it.
    """
    content = await file.read()

    try:
        pages = await run_in_threadpool(count_pdf_pages, content)
    except DocumentExtractionError as exc:
        status_code, error_code = _ERROR_STATUS.get(type(exc), _UNKNOWN_ERROR_STATUS)
        logger.info(
            "document_page_count_rejected",
            extra={"event": "document_page_count_rejected", "error_code": error_code},
        )
        return JSONResponse(
            status_code=status_code,
            content=ErrorResponse(error=error_code, message=str(exc)).model_dump(),
        )

    logger.info(
        "document_page_count_completed",
        extra={"event": "document_page_count_completed", "page_count": pages},
    )

    return JSONResponse(status_code=200, content={"page_count": pages})


@router.post(
    "/v1/documents/extract-text",
    response_model=ExtractTextResponse,
    responses={
        401: {"model": ErrorResponse},
        413: {"model": ErrorResponse},
        422: {"model": ErrorResponse},
    },
)
async def extract_text(
    file: Annotated[
        UploadFile,
        File(
            description="Raw document bytes. Content-type is ignored; "
            "only the byte content decides acceptance."
        ),
    ],
    _authenticated: Annotated[None, Depends(require_internal_auth)],
) -> ExtractTextResponse | JSONResponse:
    """Extracts native PDF text and the OCR routing decision.

    The uploaded bytes are validated and parsed exactly as
    `processing.documents.extract_document` defines — magic bytes, size,
    page count, encryption, and quality scoring — regardless of the
    multipart part's declared filename or content-type (`BLUEPRINT.md` §14.2:
    "ignore supplied MIME").
    """
    content = await file.read()

    try:
        result = await run_in_threadpool(extract_document, content, declared_filename=file.filename)
    except DocumentExtractionError as exc:
        status_code, error_code = _ERROR_STATUS.get(type(exc), _UNKNOWN_ERROR_STATUS)
        logger.info(
            "document_extraction_rejected",
            extra={"event": "document_extraction_rejected", "error_code": error_code},
        )
        return JSONResponse(
            status_code=status_code,
            content=ErrorResponse(error=error_code, message=str(exc)).model_dump(),
        )

    logger.info(
        "document_extraction_completed",
        extra={
            "event": "document_extraction_completed",
            "page_count": result.page_count,
            "overall_decision": result.overall_decision.value,
        },
    )
    return ExtractTextResponse.from_result(result)
