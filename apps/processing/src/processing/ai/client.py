"""HTTP client speaking the OpenAI-compatible `/v1/chat/completions` shape.

Per ADR 0007 this is the entire provider abstraction: no vendor SDK, one
HTTP client, configured by `AISettings`. Swapping OpenAI for a self-hosted
vLLM/Ollama endpoint is a config change (`AI_BASE_URL`), not a new code path.

Uses `httpx2` (github.com/pydantic/httpx2), not classic `httpx`: this
project already pulls in Starlette 1.6, whose `TestClient` deprecates
`httpx` in favour of `httpx2` (see `docs/reference/verified-versions.md`).
Standardising on one HTTP client avoids depending on the deprecated path.
"""

from __future__ import annotations

from typing import Protocol

import httpx2

from processing.ai.config import AISettings
from processing.ai.schemas import ChatCompletionRequest, ChatCompletionResult

_CHAT_COMPLETIONS_PATH = "/v1/chat/completions"


class AIProviderError(RuntimeError):
    """Raised on any non-2xx response or unexpected response shape.

    Never swallowed and never silently retried into a guessed value —
    a broken provider call is a hard failure, not a best-effort save
    (`BLUEPRINT.md` §14.4, `docs/AGENTS.md` §1 rule 14).
    """


class ChatCompletionClient(Protocol):
    """The seam other modules depend on, so tests can substitute a fake."""

    async def complete(self, request: ChatCompletionRequest) -> ChatCompletionResult: ...


class OpenAICompatibleClient:
    """Concrete `ChatCompletionClient` for any OpenAI-compatible backend."""

    def __init__(
        self,
        *,
        base_url: str,
        api_key: str | None = None,
        http_client: httpx2.AsyncClient | None = None,
    ) -> None:
        """`http_client`, if given, must already be configured with `base_url`
        (tests do this to attach an `httpx2.MockTransport`). When omitted, this
        instance owns the client it creates and closes it in `aclose`.
        """
        self._api_key = api_key
        self._owns_http_client = http_client is None
        self._http_client = http_client or httpx2.AsyncClient(base_url=base_url.rstrip("/"))

    @classmethod
    def from_settings(
        cls, settings: AISettings, *, http_client: httpx2.AsyncClient | None = None
    ) -> OpenAICompatibleClient:
        api_key = settings.api_key.get_secret_value() if settings.api_key else None
        return cls(base_url=str(settings.base_url), api_key=api_key, http_client=http_client)

    async def aclose(self) -> None:
        if self._owns_http_client:
            await self._http_client.aclose()

    async def complete(self, request: ChatCompletionRequest) -> ChatCompletionResult:
        headers = {"Authorization": f"Bearer {self._api_key}"} if self._api_key else {}
        response = await self._http_client.post(
            _CHAT_COMPLETIONS_PATH,
            json=request.model_dump(mode="json", exclude_none=True),
            headers=headers,
        )
        if not response.is_success:
            raise AIProviderError(
                f"provider returned HTTP {response.status_code} for {_CHAT_COMPLETIONS_PATH}"
            )

        body = response.json()
        try:
            choice = body["choices"][0]
            content: str = choice["message"]["content"]
            finish_reason: str | None = choice.get("finish_reason")
        except (KeyError, IndexError, TypeError) as exc:
            raise AIProviderError("provider response missing choices[0].message.content") from exc

        return ChatCompletionResult(content=content, finish_reason=finish_reason, raw=body)
