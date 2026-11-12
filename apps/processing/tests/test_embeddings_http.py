"""`POST /v1/embeddings` — the call Laravel's legislation indexing makes, a batch at a time.

A batch is seconds of CPU. Run on the event loop, it held every other request of the service until
it was done, and the health check, which allows a few seconds, reported the service down while laws
were indexed. A model stands in that blocks until the test lets it go.
"""

import asyncio
import threading
from collections.abc import Iterator

import httpx2
import pytest

from processing.embeddings import http as embeddings_http
from processing.main import app

_TOKEN = "test-shared-secret-token"


class _HeldModel:
    """A model whose embedding waits until released: a batch still being computed."""

    identifier = "example-model"

    def __init__(self) -> None:
        self.started = threading.Event()
        self.release = threading.Event()

    def embed(self, texts: list[str], *, is_query: bool) -> list[list[float]]:
        self.started.set()
        self.release.wait(timeout=5)

        return [[0.6, 0.8] for _ in texts]


@pytest.fixture(autouse=True)
def internal_service_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("INTERNAL_SERVICE_AUTH_TOKEN", _TOKEN)


@pytest.fixture
def held() -> Iterator[_HeldModel]:
    model = _HeldModel()
    app.dependency_overrides[embeddings_http.model] = lambda: model
    yield model
    model.release.set()
    app.dependency_overrides.clear()


async def test_answers_the_health_check_while_a_batch_is_embedded(held: _HeldModel) -> None:
    async with httpx2.AsyncClient(
        transport=httpx2.ASGITransport(app=app), base_url="http://processing"
    ) as client:
        embedding = asyncio.create_task(
            client.post(
                "/v1/embeddings",
                headers={"Authorization": f"Bearer {_TOKEN}"},
                json={"texts": ["un exemplu de text"], "input_type": "passage"},
            )
        )
        await asyncio.to_thread(held.started.wait, 5)

        health = await asyncio.wait_for(client.get("/health"), timeout=2)

        assert health.status_code == 200
        assert not embedding.done()

        held.release.set()
        answered = await embedding

    assert answered.status_code == 200
    assert answered.json() == {"model": "example-model", "dimension": 2, "vectors": [[0.6, 0.8]]}
