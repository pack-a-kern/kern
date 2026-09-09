from fastapi.testclient import TestClient


def test_health_returns_ok(client: TestClient) -> None:
    response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "processing"}


def test_health_response_carries_a_correlation_id(client: TestClient) -> None:
    response = client.get("/health")

    assert "X-Request-Id" in response.headers
    assert len(response.headers["X-Request-Id"]) > 0


def test_inbound_correlation_id_is_echoed_back(client: TestClient) -> None:
    response = client.get("/health", headers={"X-Request-Id": "test-correlation-id-123"})

    assert response.headers["X-Request-Id"] == "test-correlation-id-123"
