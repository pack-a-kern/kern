"""AI provider configuration (ADR 0007).

`AISettings` is deliberately the *only* provider-specific configuration in
this codebase: because the abstraction is the OpenAI-compatible wire
protocol, one client (`OpenAICompatibleClient`) serves every backend that
speaks it (OpenAI itself, or a self-hosted vLLM/Ollama/etc. endpoint).
`provider` is carried through for observability/logging only — it never
selects different request-building or parsing code.

No provider SDK is a dependency of this project. That is the point of
ADR 0007: swapping backends is a config change, not a code change.
"""

from pydantic import Field, HttpUrl, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class AISettings(BaseSettings):
    """Loaded from `AI_PROVIDER`, `AI_BASE_URL`, `AI_MODEL`, `AI_API_KEY`."""

    model_config = SettingsConfigDict(env_prefix="AI_", extra="forbid", frozen=True)

    provider: str = Field(min_length=1, description="Label for logs/metrics only.")
    base_url: HttpUrl = Field(description="Base URL; `/v1/chat/completions` is appended.")
    model: str = Field(min_length=1, description="Model identifier sent as the `model` field.")
    api_key: SecretStr | None = Field(
        default=None,
        description="Sent as `Authorization: Bearer <key>`. Optional for backends that don't "
        "require one (e.g. an unauthenticated local vLLM/Ollama endpoint).",
    )
