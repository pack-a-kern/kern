"""`POST /v1/statements/parse` — the endpoint Laravel's statement import calls.

The contract is asserted field by field, like the document endpoints': a caller reads `columns` to
propose a mapping and `rows` to build transactions, and a silently renamed field would break both.
"""

import io

import pytest
from fastapi.testclient import TestClient

_TOKEN = "test-shared-secret-token"

_CSV = (
    "Data;Detalii;Debit;Credit\r\n05.03.2026;PLATA CARD;123,45;\r\n06.03.2026;SALARIU;;7.500,00\r\n"
)


@pytest.fixture(autouse=True)
def internal_service_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("INTERNAL_SERVICE_AUTH_TOKEN", _TOKEN)


@pytest.fixture
def auth_headers() -> dict[str, str]:
    return {"Authorization": f"Bearer {_TOKEN}"}


def _post(
    client: TestClient, headers: dict[str, str] | None, content: bytes, filename: str = "extras.csv"
):
    return client.post(
        "/v1/statements/parse",
        headers=headers or {},
        files={"file": (filename, content, "application/octet-stream")},
    )


def test_refuses_an_unauthenticated_call(client: TestClient) -> None:
    assert _post(client, None, _CSV.encode()).status_code == 401


def test_returns_rows_and_described_columns(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    response = _post(client, auth_headers, _CSV.encode())

    assert response.status_code == 200

    body = response.json()

    assert body["format"] == "CSV"
    assert body["header_row"] == 0
    assert body["row_count"] == 2
    assert body["rows"][0][0] == "05.03.2026"
    assert [column["header"] for column in body["columns"]] == [
        "Data",
        "Detalii",
        "Debit",
        "Credit",
    ]
    assert body["columns"][0]["shape"] == "date DD.MM.YYYY"
    assert body["columns"][0]["filled_ratio"] == 1.0


def test_reads_a_workbook_and_lists_its_sheets(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    import openpyxl

    workbook = openpyxl.Workbook()
    sheet = workbook.active
    assert sheet is not None
    sheet.title = "Extras"
    sheet.append(["Data", "Suma"])
    sheet.append(["05.03.2026", "10,00"])
    buffer = io.BytesIO()
    workbook.save(buffer)

    response = _post(client, auth_headers, buffer.getvalue(), filename="extras.xlsx")

    assert response.status_code == 200
    assert response.json()["format"] == "XLSX"
    assert response.json()["sheets"] == ["Extras"]


def test_a_scanned_pdf_is_refused_with_the_code_the_screen_translates(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    from pypdf import PdfWriter

    writer = PdfWriter()
    writer.add_blank_page(width=595, height=842)
    buffer = io.BytesIO()
    writer.write(buffer)

    response = _post(client, auth_headers, buffer.getvalue(), filename="extras.pdf")

    assert response.status_code == 422
    assert response.json()["error"] == "NO_TEXT_LAYER"


def test_an_unreadable_file_is_refused_rather_than_guessed_at(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    response = _post(client, auth_headers, b"\x00\x01\x02\x03not a statement")

    assert response.status_code == 422
    assert response.json()["error"] == "UNSUPPORTED_FORMAT"


def test_says_nothing_about_the_content_in_its_message(
    client: TestClient, auth_headers: dict[str, str]
) -> None:
    response = _post(client, auth_headers, b"\x00\x01\x02\x03KAUFLAND BUCURESTI")

    assert "KAUFLAND" not in response.text
