import pytest
from fastapi.testclient import TestClient

from processing.main import app


@pytest.fixture
def client() -> TestClient:
    return TestClient(app)
