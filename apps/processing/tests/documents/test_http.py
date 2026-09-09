"""`POST /v1/documents/extract-text` — the HTTP surface Laravel's
`apps/api` `HttpTextExtractionGateway` calls (`docs/handoff/
024-extraction-endpoint.md`). Every test asserts the actual response shape
field-by-field against the documented contract, not just the status code —
that is the point of building this endpoint (`docs/AGENTS.md` §2)."""

import pytest
from fastapi.testclient import TestClient
from pdf_builders import (
    build_decompression_bomb_pdf,
    build_encrypted_pdf,
    build_text_pdf,
)

_TOKEN = "test-shared-secret-token"


@pytest.fixture(autouse=True)
def internal_service_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("INTERNAL_SERVICE_AUTH_TOKEN", _TOKEN)


@pytest.fixture
def auth_headers() -> dict[str, str]:
    return {"Authorization": f"Bearer {_TOKEN}"}


def _post_pdf(
    client: TestClient, headers: dict[str, str], pdf_bytes: bytes, filename: str = "document.pdf"
):
    return client.post(
        "/v1/documents/extract-text",
        headers=headers,
        files={"file": (filename, pdf_bytes, "application/pdf")},
    )


# --- Authentication (BLUEPRINT.md §1.2/§3.3 — must not be callable unauthenticated) ---


def test_missing_authorization_header_is_rejected(client: TestClient) -> None:
    response = _post_pdf(client, {}, build_text_pdf())

    assert response.status_code == 401
    assert response.json() == {
        "error": "unauthorized",
        "message": "Missing or malformed Authorization header.",
    }


def test_malformed_authorization_header_is_rejected(client: TestClient) -> None:
    response = _post_pdf(client, {"Authorization": _TOKEN}, build_text_pdf())  # no "Bearer "

    assert response.status_code == 401
    assert response.json()["error"] == "unauthorized"


def test_wrong_token_is_rejected(client: TestClient) -> None:
    response = _post_pdf(client, {"Authorization": "Bearer wrong-token"}, build_text_pdf())

    assert response.status_code == 401
    assert response.json() == {
        "error": "unauthorized",
        "message": "Invalid service authentication token.",
    }


def test_missing_server_side_token_configuration_fails_closed(
    client: TestClient, monkeypatch: pytest.MonkeyPatch
) -> None:
    """If the deployment forgot to set `INTERNAL_SERVICE_AUTH_TOKEN`, every
    request must be rejected — never silently accepted as unauthenticated."""
    monkeypatch.delenv("INTERNAL_SERVICE_AUTH_TOKEN", raising=False)

    response = _post_pdf(client, {"Authorization": f"Bearer {_TOKEN}"}, build_text_pdf())

    assert response.status_code == 401
    assert response.json() == {
        "error": "unauthorized",
        "message": "Service authentication is not configured.",
    }


# --- The documented success contract, field by field ---


def test_a_clean_text_layer_pdf_matches_the_documented_response_shape(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    response = _post_pdf(client, auth_headers, build_text_pdf())

    assert response.status_code == 200
    body = response.json()

    assert body["page_count"] == 1
    assert body["needs_ocr"] is False
    assert body["overall_decision"] == "native_text_sufficient"
    assert body["pages_needing_ocr"] == []
    assert len(body["pages"]) == 1

    page = body["pages"][0]
    assert page["page_number"] == 1
    assert page["needs_ocr"] is False
    assert isinstance(page["quality_score"], str)
    float(page["quality_score"])  # decimal string, parseable, never a bare float on the wire
    signals = page["quality_signals"]
    assert set(signals.keys()) == {
        "non_whitespace_char_count",
        "total_char_count",
        "page_area_sq_inches",
        "char_density_per_sq_inch",
        "bad_char_ratio",
        "recognizable_word_ratio",
        "is_whitespace_only",
    }
    assert signals["non_whitespace_char_count"] > 0
    assert page["text"]["kind"] == "untrusted_document_text"
    assert "Factura" in page["text"]["content"]


def test_an_image_only_pdf_needs_ocr(client: TestClient, auth_headers: dict[str, str]) -> None:
    from pdf_builders import build_blank_pdf

    response = _post_pdf(client, auth_headers, build_blank_pdf())

    assert response.status_code == 200
    body = response.json()

    assert body["needs_ocr"] is True
    assert body["overall_decision"] == "ocr_required"
    assert body["pages_needing_ocr"] == [1]
    assert body["pages"][0]["needs_ocr"] is True
    assert body["pages"][0]["text"]["content"] == ""


# --- Structured, specific errors — "this document is bad", not "the service failed" ---


def test_an_encrypted_pdf_is_reported_as_a_specific_error(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    response = _post_pdf(client, auth_headers, build_encrypted_pdf())

    assert response.status_code == 422
    assert response.json() == {
        "error": "encrypted_pdf",
        "message": "PDF is encrypted; rejected without attempting a password",
    }


def test_an_oversized_file_is_reported_as_a_specific_error(
    client: TestClient, auth_headers: dict[str, str], monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv("DOC_EXTRACTION_MAX_FILE_SIZE_BYTES", "500")

    response = _post_pdf(client, auth_headers, build_text_pdf())

    assert response.status_code == 413
    body = response.json()
    assert body["error"] == "file_too_large"


def test_a_magic_byte_mismatch_is_reported_as_a_specific_error(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    response = _post_pdf(client, auth_headers, b"this is not a pdf at all", filename="fake.pdf")

    assert response.status_code == 422
    body = response.json()
    assert body["error"] == "invalid_magic_bytes"


def test_a_caller_declared_content_type_never_overrides_the_byte_check(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    """§14.2 — 'ignore supplied MIME'. A PNG's own bytes, wrapped in a
    request that claims `application/pdf`, must still be rejected."""
    png_bytes = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64
    response = client.post(
        "/v1/documents/extract-text",
        headers=auth_headers,
        files={"file": ("invoice.pdf", png_bytes, "application/pdf")},
    )

    assert response.status_code == 422
    assert response.json()["error"] == "invalid_magic_bytes"


def test_a_decompression_bomb_is_reported_as_a_specific_error(
    client: TestClient, auth_headers: dict[str, str], monkeypatch: pytest.MonkeyPatch
) -> None:
    """Same defence and fixture `docs/handoff/019-pdf-text-extraction.md`
    measured directly (~11s natural completion vs. a tight timeout budget
    here) — confirms the HTTP layer surfaces it as a distinct, specific
    error rather than a generic 500/timeout."""
    monkeypatch.setenv("DOC_EXTRACTION_PARSE_TIMEOUT_SECONDS", "0.5")

    response = _post_pdf(client, auth_headers, build_decompression_bomb_pdf(repeats=400_000))

    assert response.status_code == 422
    assert response.json()["error"] == "extraction_timeout"


def test_a_malformed_request_body_is_rejected(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    """No `file` part at all — FastAPI's own request validation, not this
    endpoint's document-rejection codes, since the request never reached
    `extract_document`."""
    response = client.post("/v1/documents/extract-text", headers=auth_headers)

    assert response.status_code == 422
    assert "detail" in response.json()
