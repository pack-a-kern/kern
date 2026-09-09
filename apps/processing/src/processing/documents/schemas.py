"""DTOs for PDF text-layer extraction and the OCR routing decision.

`UntrustedDocumentText` is the prompt-injection boundary `BLUEPRINT.md` §14.5
requires: document content is DATA, and this type exists so a future
(Milestone 7) caller cannot casually splice it into an instruction string.
See `docs/handoff/019-pdf-text-extraction.md` for the full contract.
"""

from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field


class UntrustedDocumentText:
    """Extracted PDF text, wrapped as untrusted DATA (`BLUEPRINT.md` §14.5).

    Deliberately not a `str` subclass and offers no `__str__`/`__format__`
    passthrough: a plain string is too easy to interpolate into an
    instruction/system prompt by accident (`f"...{text}..."` renders the
    redacted `repr()` below, never the document content). Any Milestone 7
    code that builds a model prompt from this MUST:

    - call `.reveal()` explicitly to obtain the raw text — grep-able, so a
      reviewer can find every place raw document content leaves this type;
    - pass the revealed text only as clearly-delimited DATA (e.g. a separate
      user-turn/tool-input field), never concatenated into the system or
      instruction message;
    - never let an instruction-shaped sentence found inside the text change
      which instructions the model follows — the extraction model this
      feeds is never given tools (`processing.ai.schemas.ChatMessage.role`
      already excludes `tool`/`function`) and must treat document content
      as reportable data, not as commands to obey.
    """

    __slots__ = ("_value",)

    def __init__(self, value: str) -> None:
        self._value = value

    def reveal(self) -> str:
        """Explicit escape hatch to the raw text. Named deliberately so it
        is easy to audit every call site that needs raw document content."""
        return self._value

    def __len__(self) -> int:
        return len(self._value)

    def __eq__(self, other: object) -> bool:
        return isinstance(other, UntrustedDocumentText) and self._value == other._value

    def __hash__(self) -> int:
        return hash(self._value)

    def __repr__(self) -> str:
        return f"UntrustedDocumentText(<{len(self._value)} chars, redacted>)"


class PageQualitySignals(BaseModel):
    """The measurable signals behind one page's OCR decision — never a vibe.

    See `docs/handoff/019-pdf-text-extraction.md` for what each threshold
    means and why it was chosen.
    """

    model_config = ConfigDict(frozen=True, extra="forbid")

    non_whitespace_char_count: int = Field(ge=0)
    total_char_count: int = Field(ge=0)
    page_area_sq_inches: float = Field(ge=0)
    char_density_per_sq_inch: float = Field(ge=0)
    bad_char_ratio: float = Field(ge=0, le=1)
    recognizable_word_ratio: float = Field(ge=0, le=1)
    is_whitespace_only: bool


class PageExtractionResult(BaseModel):
    """The extraction outcome and OCR decision for a single page (1-indexed)."""

    model_config = ConfigDict(frozen=True, extra="forbid", arbitrary_types_allowed=True)

    page_number: int = Field(ge=1)
    text: UntrustedDocumentText
    quality_signals: PageQualitySignals
    quality_score: float = Field(ge=0, le=1)
    needs_ocr: bool


class OcrDecision(StrEnum):
    """The document-level routing decision ADR 0005 acts on."""

    NATIVE_TEXT_SUFFICIENT = "native_text_sufficient"
    OCR_REQUIRED = "ocr_required"


class DocumentExtractionResult(BaseModel):
    """The full per-document result: every page's extraction plus the
    routing decision Laravel/ADR 0005 needs.

    No page's `text` may ever leave this process over a Reverb broadcast
    (`BLUEPRINT.md` §16 — broadcast events carry status/progress only). A
    caller that needs to notify a client of progress re-fetches this result
    over authorised HTTPS instead.
    """

    model_config = ConfigDict(frozen=True, extra="forbid")

    page_count: int = Field(ge=1)
    pages: list[PageExtractionResult]
    overall_decision: OcrDecision
    pages_needing_ocr: list[int]
