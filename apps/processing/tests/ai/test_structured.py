import pytest
from pydantic import BaseModel

from processing.ai.schemas import ChatCompletionResult
from processing.ai.structured import StructuredOutputError, parse_structured_output


class _Point(BaseModel):
    x: int
    y: int


def _result(content: str) -> ChatCompletionResult:
    return ChatCompletionResult(content=content, finish_reason="stop", raw={})


def test_parses_valid_json_into_schema() -> None:
    point = parse_structured_output(_result('{"x": 1, "y": 2}'), _Point)

    assert point == _Point(x=1, y=2)


def test_raises_on_invalid_json() -> None:
    with pytest.raises(StructuredOutputError):
        parse_structured_output(_result("not json"), _Point)


def test_raises_on_schema_mismatch() -> None:
    with pytest.raises(StructuredOutputError):
        parse_structured_output(_result('{"x": "not-an-int"}'), _Point)
