"""Wire-shape DTOs for the OpenAI-compatible chat completions endpoint.

These mirror the request/response shape of `POST /v1/chat/completions`
exactly enough to build a request and read a completion back — they are not
a general-purpose OpenAI API client. `role` excludes `tool`/`function`
deliberately: the extraction model this will feed (Milestone 7) is never
given tools (`BLUEPRINT.md` §14.5), so the type does not offer that shape.
"""

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class ChatMessage(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    role: Literal["system", "user", "assistant"]
    content: str


class ChatCompletionRequest(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    model: str = Field(min_length=1)
    messages: list[ChatMessage] = Field(min_length=1)
    temperature: float = Field(default=0.0, ge=0.0, le=2.0)
    max_tokens: int | None = Field(default=None, gt=0)
    response_format: dict[str, Any] | None = Field(
        default=None,
        description="Raw OpenAI-compatible response_format object, "
        'e.g. {"type": "json_object"}. Passed through verbatim.',
    )


class ChatCompletionResult(BaseModel):
    model_config = ConfigDict(frozen=True, extra="forbid")

    content: str
    finish_reason: str | None
    raw: dict[str, Any] = Field(description="Full decoded JSON response, for audit/debug.")
