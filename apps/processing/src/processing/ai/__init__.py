"""AI provider abstraction (ADR 0007).

The abstraction boundary is the wire protocol, not a per-vendor SDK: any
backend that speaks the OpenAI-compatible `/v1/chat/completions` shape works
through `OpenAICompatibleClient`, configured entirely by the `AI_PROVIDER`,
`AI_BASE_URL`, `AI_MODEL` and `AI_API_KEY` environment variables (`AISettings`).

This package intentionally contains interface and config plumbing only.
Document extraction prompts, schemas and OCR orchestration are Milestone 7.
"""

from processing.ai.client import AIProviderError, OpenAICompatibleClient
from processing.ai.config import AISettings
from processing.ai.schemas import ChatCompletionRequest, ChatCompletionResult, ChatMessage
from processing.ai.structured import StructuredOutputError, parse_structured_output

__all__ = [
    "AIProviderError",
    "AISettings",
    "ChatCompletionRequest",
    "ChatCompletionResult",
    "ChatMessage",
    "OpenAICompatibleClient",
    "StructuredOutputError",
    "parse_structured_output",
]
