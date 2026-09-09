import json

import httpx2
import pytest
from pydantic import HttpUrl, SecretStr

from processing.ai.client import AIProviderError, OpenAICompatibleClient
from processing.ai.config import AISettings
from processing.ai.schemas import ChatCompletionRequest, ChatMessage


def _client_with_transport(
    transport: httpx2.MockTransport, api_key: str | None = None
) -> OpenAICompatibleClient:
    http_client = httpx2.AsyncClient(base_url="http://ai-provider.test", transport=transport)
    return OpenAICompatibleClient(
        base_url="http://ai-provider.test", api_key=api_key, http_client=http_client
    )


async def test_complete_sends_expected_request_and_parses_response() -> None:
    captured: dict[str, httpx2.Request] = {}

    def handler(request: httpx2.Request) -> httpx2.Response:
        captured["request"] = request
        return httpx2.Response(
            200,
            json={
                "id": "chatcmpl-1",
                "choices": [
                    {
                        "index": 0,
                        "message": {"role": "assistant", "content": "hello"},
                        "finish_reason": "stop",
                    }
                ],
            },
        )

    client = _client_with_transport(httpx2.MockTransport(handler), api_key="s3cr3t")
    request = ChatCompletionRequest(
        model="some-model", messages=[ChatMessage(role="user", content="hi")]
    )

    result = await client.complete(request)

    assert result.content == "hello"
    assert result.finish_reason == "stop"
    sent = captured["request"]
    assert sent.url.path == "/v1/chat/completions"
    assert sent.headers["Authorization"] == "Bearer s3cr3t"
    body = json.loads(sent.content)
    assert body["model"] == "some-model"
    assert body["messages"] == [{"role": "user", "content": "hi"}]


async def test_complete_without_api_key_sends_no_authorization_header() -> None:
    captured: dict[str, httpx2.Request] = {}

    def handler(request: httpx2.Request) -> httpx2.Response:
        captured["request"] = request
        return httpx2.Response(
            200,
            json={"choices": [{"message": {"content": "hi"}, "finish_reason": "stop"}]},
        )

    client = _client_with_transport(httpx2.MockTransport(handler), api_key=None)
    request = ChatCompletionRequest(model="m", messages=[ChatMessage(role="user", content="hi")])

    await client.complete(request)

    assert "Authorization" not in captured["request"].headers


async def test_non_200_response_raises_ai_provider_error() -> None:
    def handler(_: httpx2.Request) -> httpx2.Response:
        return httpx2.Response(503, json={"error": "unavailable"})

    client = _client_with_transport(httpx2.MockTransport(handler))
    request = ChatCompletionRequest(model="m", messages=[ChatMessage(role="user", content="hi")])

    with pytest.raises(AIProviderError):
        await client.complete(request)


async def test_unexpected_response_shape_raises_ai_provider_error() -> None:
    def handler(_: httpx2.Request) -> httpx2.Response:
        return httpx2.Response(200, json={"unexpected": "shape"})

    client = _client_with_transport(httpx2.MockTransport(handler))
    request = ChatCompletionRequest(model="m", messages=[ChatMessage(role="user", content="hi")])

    with pytest.raises(AIProviderError):
        await client.complete(request)


async def test_from_settings_builds_a_working_client() -> None:
    def handler(request: httpx2.Request) -> httpx2.Response:
        assert request.headers["Authorization"] == "Bearer settings-key"
        return httpx2.Response(
            200, json={"choices": [{"message": {"content": "ok"}, "finish_reason": "stop"}]}
        )

    settings = AISettings(
        provider="local-vllm",
        base_url=HttpUrl("http://ai-provider.test"),
        model="m",
        api_key=SecretStr("settings-key"),
    )
    http_client = httpx2.AsyncClient(
        base_url="http://ai-provider.test", transport=httpx2.MockTransport(handler)
    )
    client = OpenAICompatibleClient.from_settings(settings, http_client=http_client)

    result = await client.complete(
        ChatCompletionRequest(model="m", messages=[ChatMessage(role="user", content="hi")])
    )

    assert result.content == "ok"
