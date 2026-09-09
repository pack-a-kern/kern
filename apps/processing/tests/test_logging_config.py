import json
import logging

from processing.logging_config import (
    CorrelationIdLogFilter,
    JsonLogFormatter,
    correlation_id_var,
)


def _make_record(**extra: object) -> logging.LogRecord:
    record = logging.LogRecord(
        name="processing.test",
        level=logging.INFO,
        pathname=__file__,
        lineno=1,
        msg="something happened",
        args=(),
        exc_info=None,
    )
    for key, value in extra.items():
        setattr(record, key, value)
    return record


def test_formatter_emits_valid_json_with_core_fields() -> None:
    record = _make_record()
    formatter = JsonLogFormatter()

    parsed = json.loads(formatter.format(record))

    assert parsed["message"] == "something happened"
    assert parsed["level"] == "INFO"
    assert parsed["logger"] == "processing.test"
    assert "timestamp" in parsed


def test_formatter_includes_only_allowlisted_extra_fields() -> None:
    record = _make_record(event="http_request", status_code=200, cnp="1234567890123")
    formatter = JsonLogFormatter()

    parsed = json.loads(formatter.format(record))

    assert parsed["event"] == "http_request"
    assert parsed["status_code"] == 200
    assert "cnp" not in parsed, "unlisted extra fields must never reach the log line"


def test_correlation_filter_stamps_record_from_contextvar() -> None:
    token = correlation_id_var.set("corr-abc")
    try:
        record = _make_record()
        assert CorrelationIdLogFilter().filter(record) is True
        assert getattr(record, "correlation_id", None) == "corr-abc"
    finally:
        correlation_id_var.reset(token)


def test_correlation_filter_defaults_to_none_outside_a_request() -> None:
    record = _make_record()
    CorrelationIdLogFilter().filter(record)

    assert getattr(record, "correlation_id", None) is None
