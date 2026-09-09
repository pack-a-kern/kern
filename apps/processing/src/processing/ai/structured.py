"""Structured-output enforcement seam (ADR 0007).

A model's raw completion text becomes a typed object here, or the call
fails outright. There is no repair pass and no partial/best-effort
acceptance: `BLUEPRINT.md` §14.4 requires that invalid structured output is a
failure, never a silently-saved guess. Prompting the model to actually
produce that JSON (schema-in-prompt, `response_format`, retries, etc.) is
Milestone 7 — this module only validates whatever content it is given.
"""

from pydantic import BaseModel, ValidationError

from processing.ai.schemas import ChatCompletionResult


class StructuredOutputError(ValueError):
    """Raised when a completion's content does not validate against the schema."""


def parse_structured_output[SchemaT: BaseModel](
    result: ChatCompletionResult, schema: type[SchemaT]
) -> SchemaT:
    """Validate `result.content` as JSON matching `schema`, or raise."""
    try:
        return schema.model_validate_json(result.content)
    except ValidationError as exc:
        raise StructuredOutputError(
            f"completion content does not match {schema.__name__}: {exc}"
        ) from exc
