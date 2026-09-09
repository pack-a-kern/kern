"""`POST /v1/embeddings` — internal-only, same shared-secret guard as document
extraction (`processing.internal_auth`).

Kept separate from the documents router because the two have different failure
modes and different callers: extraction rejects *this document*, embedding
either works or the service is unavailable. There is no per-text rejection.
"""

from fastapi import APIRouter, Depends, HTTPException, status

from processing.embeddings.config import EmbeddingSettings
from processing.embeddings.model import EmbeddingModel, EmbeddingModelError
from processing.embeddings.schemas import EmbedRequest, EmbedResponse
from processing.internal_auth import require_internal_auth

router = APIRouter()

_settings = EmbeddingSettings()
_model = EmbeddingModel(_settings)


def model() -> EmbeddingModel:
    """Seam for tests to substitute a model that needs no weights download."""
    return _model


_MODEL_DEPENDENCY = Depends(model)


@router.post(
    "/v1/embeddings",
    response_model=EmbedResponse,
    dependencies=[Depends(require_internal_auth)],
)
async def embed(
    request: EmbedRequest,
    embedding_model: EmbeddingModel = _MODEL_DEPENDENCY,
) -> EmbedResponse:
    try:
        vectors = embedding_model.embed(request.texts, is_query=request.input_type == "query")
    except EmbeddingModelError as cause:
        # 503, not 500: the model being absent or unloadable is an operational
        # state the caller should retry against, not a defect in its request.
        # The caller leaves the affected chunks unembedded and moves on.
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={"error": "embedding_unavailable", "message": str(cause)},
        ) from cause

    return EmbedResponse(
        model=embedding_model.identifier,
        dimension=len(vectors[0]) if vectors else 0,
        vectors=vectors,
    )
