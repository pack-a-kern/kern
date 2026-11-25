"""Request and response shapes for `POST /v1/embeddings`."""

from typing import Annotated, Literal

from pydantic import BaseModel, ConfigDict, Field

from processing.embeddings.config import EmbeddingSettings

# Read once at import, from `EMBEDDING_*`, so the request bounds are configured
# in the same place as the model's own limits rather than written as magic
# numbers at this call site (`docs/CODE-QUALITY.md` §4).
_limits = EmbeddingSettings()


class EmbedRequest(BaseModel):
    """Texts to embed, in the order the caller wants them back.

    Both bounds are denial-of-service limits, not model limits — the tokenizer
    truncates each text to `EMBEDDING_MAX_TOKENS` regardless. `max_texts` caps
    how many texts one request carries, and `max_input_chars` caps each text's
    length, so a caller cannot make this service hold megabytes per element
    before that truncation ever runs.
    """

    model_config = ConfigDict(extra="forbid")

    texts: list[Annotated[str, Field(max_length=_limits.max_input_chars)]] = Field(
        min_length=1, max_length=_limits.max_texts
    )
    input_type: Literal["query", "passage"] = Field(
        description="Which training-time prefix to apply. Required, with no "
        "default: embedding a search query as a passage produces a well-formed "
        "vector in the wrong part of the space, and retrieval then degrades "
        "quietly rather than failing. The caller must state which it means."
    )


class EmbedResponse(BaseModel):
    """Vectors in the same order as the request's texts.

    `model` and `dimension` are echoed so the caller can record which vector
    space each row belongs to, rather than assuming its own configuration
    matched what actually ran.
    """

    model_config = ConfigDict(extra="forbid")

    model: str
    dimension: int
    vectors: list[list[float]]
