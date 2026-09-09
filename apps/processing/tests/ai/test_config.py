import pytest
from pydantic import ValidationError

from processing.ai.config import AISettings


def test_loads_from_ai_prefixed_env_vars(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AI_PROVIDER", "local-vllm")
    monkeypatch.setenv("AI_BASE_URL", "http://ai-provider.internal:8000")
    monkeypatch.setenv("AI_MODEL", "some-model")
    monkeypatch.setenv("AI_API_KEY", "s3cr3t")

    # pyright sees required fields with no default as mandatory constructor args; it
    # doesn't model pydantic-settings' env-var sourcing, so this call is a known
    # false positive (upstream: pydantic-settings ships no pyright plugin).
    settings = AISettings()  # pyright: ignore[reportCallIssue]

    assert settings.provider == "local-vllm"
    assert str(settings.base_url) == "http://ai-provider.internal:8000/"
    assert settings.model == "some-model"
    assert settings.api_key is not None
    assert settings.api_key.get_secret_value() == "s3cr3t"


def test_api_key_is_optional(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("AI_API_KEY", raising=False)
    monkeypatch.setenv("AI_PROVIDER", "local-ollama")
    monkeypatch.setenv("AI_BASE_URL", "http://localhost:11434")
    monkeypatch.setenv("AI_MODEL", "some-model")

    # pyright sees required fields with no default as mandatory constructor args; it
    # doesn't model pydantic-settings' env-var sourcing, so this call is a known
    # false positive (upstream: pydantic-settings ships no pyright plugin).
    settings = AISettings()  # pyright: ignore[reportCallIssue]

    assert settings.api_key is None


def test_missing_required_field_raises(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.delenv("AI_PROVIDER", raising=False)
    monkeypatch.delenv("AI_BASE_URL", raising=False)
    monkeypatch.delenv("AI_MODEL", raising=False)
    monkeypatch.delenv("AI_API_KEY", raising=False)

    with pytest.raises(ValidationError):
        AISettings()  # pyright: ignore[reportCallIssue]


def test_api_key_never_appears_in_repr(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AI_PROVIDER", "local-vllm")
    monkeypatch.setenv("AI_BASE_URL", "http://ai-provider.internal:8000")
    monkeypatch.setenv("AI_MODEL", "some-model")
    monkeypatch.setenv("AI_API_KEY", "s3cr3t-value")

    # pyright sees required fields with no default as mandatory constructor args; it
    # doesn't model pydantic-settings' env-var sourcing, so this call is a known
    # false positive (upstream: pydantic-settings ships no pyright plugin).
    settings = AISettings()  # pyright: ignore[reportCallIssue]

    assert "s3cr3t-value" not in repr(settings)
    assert "s3cr3t-value" not in str(settings)
