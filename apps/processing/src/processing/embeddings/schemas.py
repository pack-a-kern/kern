"""Request and response shapes for `POST /v1/embeddings`."""

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class EmbedRequest(BaseModel):
    """Texts to embed, in the order the caller wants them back.

    `max_length` on each item is a denial-of-service bound, not a model limit —
    the tokenizer truncates to `EMBEDDING_MAX_TOKENS` regardless, but a caller
    should not be able to make this service allocate megabytes per element.
    """

    model_config = ConfigDict(extra="forbid")

    texts: list[str] = Field(min_length=1, max_length=256)
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
