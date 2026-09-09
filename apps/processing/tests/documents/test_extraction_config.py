"""`DocumentExtractionSettings` loads from `DOC_EXTRACTION_*` env vars and
has safe defaults when unconfigured."""

import pytest

from processing.documents.config import DocumentExtractionSettings


def test_defaults_apply_when_unconfigured() -> None:
    settings = DocumentExtractionSettings()

    assert settings.max_file_size_bytes == 26_214_400
    assert settings.max_pages == 200
    assert settings.min_quality_score == 0.5


def test_loads_overrides_from_doc_extraction_prefixed_env_vars(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("DOC_EXTRACTION_MAX_FILE_SIZE_BYTES", "1000")
    monkeypatch.setenv("DOC_EXTRACTION_MAX_PAGES", "5")
    monkeypatch.setenv("DOC_EXTRACTION_MIN_QUALITY_SCORE", "0.75")

    settings = DocumentExtractionSettings()

    assert settings.max_file_size_bytes == 1000
    assert settings.max_pages == 5
    assert settings.min_quality_score == 0.75


def test_rejects_an_unknown_field() -> None:
    with pytest.raises(Exception, match="extra"):
        DocumentExtractionSettings(not_a_real_field="x")  # pyright: ignore[reportCallIssue]


def test_settings_instance_is_frozen() -> None:
    settings = DocumentExtractionSettings()

    with pytest.raises(Exception, match="frozen"):
        settings.max_pages = 1  # pyright: ignore[reportAttributeAccessIssue]
