"""`POST /v1/spreadsheets/grid` — a workbook's sheet as plain rows, for a caller that knows its
layout.

Built like the Ministry of Finance's issue lists: a year heading inside the table, a date written
once on a merged round, a total row, and a first column left empty. Made-up figures; built here,
not committed as a file.
"""

import datetime
import io

import pytest
from fastapi.testclient import TestClient

from processing.statements.config import StatementParseSettings
from processing.statements.errors import TooManyRowsError, UnsupportedFormatError
from processing.statements.parse import read_grid

_TOKEN = "test-shared-secret-token"


@pytest.fixture(autouse=True)
def internal_service_token(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("INTERNAL_SERVICE_AUTH_TOKEN", _TOKEN)


def _issue_list() -> bytes:
    import openpyxl

    workbook = openpyxl.Workbook()
    sheet = workbook.active
    assert sheet is not None
    sheet.title = "Emisiuni"
    sheet.append([None, "Cod emisiune", "Data emisiunii", "Valoare subscrisa", "Nr. subscrieri"])
    sheet.append([None, "2026"])
    sheet.append([None, "0001", datetime.datetime(2026, 2, 1), 1500000.5, 120])
    sheet.append([None, "0002", None, 250000, 30])
    sheet.merge_cells("C3:C4")
    sheet["C3"].number_format = "mmm-yy"
    sheet.append([None, "Total 2026", None, 1750000.5, 150])

    out = io.BytesIO()
    workbook.save(out)

    return out.getvalue()


def test_hands_back_every_row_as_text_interpreting_nothing() -> None:
    grid = read_grid(_issue_list())

    assert grid.sheet == "Emisiuni"
    assert grid.sheets == ["Emisiuni"]
    assert grid.rows == [
        ["", "Cod emisiune", "Data emisiunii", "Valoare subscrisa", "Nr. subscrieri"],
        ["", "2026"],
        ["", "0001", "2026-02-01 00:00:00", "1500000.5", "120"],
        ["", "0002", "", "250000", "30"],
        ["", "Total 2026", "", "1750000.5", "150"],
    ]


def test_refuses_what_is_not_a_workbook() -> None:
    with pytest.raises(UnsupportedFormatError):
        read_grid(b"Cod;Data\r\n0001;01.02.2026\r\n")


def test_refuses_a_sheet_longer_than_is_read() -> None:
    with pytest.raises(TooManyRowsError):
        read_grid(_issue_list(), settings=StatementParseSettings(max_rows=3))


def test_answers_the_grid_to_an_authenticated_caller_only(client: TestClient) -> None:
    files = {"file": ("FIDELIS.xlsx", _issue_list(), "application/octet-stream")}

    assert client.post("/v1/spreadsheets/grid", files=files).status_code == 401

    response = client.post(
        "/v1/spreadsheets/grid", headers={"Authorization": f"Bearer {_TOKEN}"}, files=files
    )

    assert response.status_code == 200
    body = response.json()
    assert set(body) == {"sheet", "sheets", "rows"}
    assert body["rows"][2] == ["", "0001", "2026-02-01 00:00:00", "1500000.5", "120"]


def test_refuses_a_file_it_cannot_read_with_a_code(client: TestClient) -> None:
    response = client.post(
        "/v1/spreadsheets/grid",
        headers={"Authorization": f"Bearer {_TOKEN}"},
        files={"file": ("x.xlsx", b"not a workbook", "application/octet-stream")},
    )

    assert response.status_code == 422
    assert response.json()["error"] == "UNSUPPORTED_FORMAT"
