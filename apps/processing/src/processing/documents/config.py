"""Tunable thresholds for PDF text-layer extraction and the OCR decision
(ADR 0005, `BLUEPRINT.md` §14.3).

Every threshold below is an engineering default chosen and justified in
`docs/handoff/019-pdf-text-extraction.md` — none are fiscal values, none
need a `source_section_id`, but per `docs/CODE-QUALITY.md` §4 ("no magic
numbers") they are still named, documented and overridable via
`DOC_EXTRACTION_*` environment variables rather than hard-coded at each call
site. Recalibrate once real document volume exists.
"""

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class DocumentExtractionSettings(BaseSettings):
    """Loaded from `DOC_EXTRACTION_*` environment variables; every field has
    a safe default so the service runs unconfigured in development."""

    model_config = SettingsConfigDict(env_prefix="DOC_EXTRACTION_", extra="forbid", frozen=True)

    # --- File security (`BLUEPRINT.md` §14.2) ---
    max_file_size_bytes: int = Field(
        default=26_214_400,  # 25 MiB
        gt=0,
        description="Hard ceiling on input size, checked before any parsing.",
    )
    max_pages: int = Field(
        default=200,
        gt=0,
        description="Hard ceiling on page count, checked inside the isolated "
        "worker before any page's text is extracted.",
    )
    parse_timeout_seconds: float = Field(
        default=20.0,
        gt=0,
        description="Wall-clock budget for parsing + extracting an entire "
        "document in the isolated worker process. The decompression-bomb "
        "defence: exceeding it kills the worker rather than waiting.",
    )
    max_worker_memory_bytes: int = Field(
        default=536_870_912,  # 512 MiB
        gt=0,
        description="RLIMIT_AS applied inside the isolated worker process, "
        "so a memory-exhaustion attempt fails fast instead of pressuring "
        "the whole container.",
    )
    magic_byte_scan_window: int = Field(
        default=1024,
        gt=0,
        description="How many leading bytes are scanned for the `%PDF-` "
        "signature. ISO 32000-2 §7.5.2 allows the header to follow leading "
        "bytes some generators prepend, so this is a window, not offset 0.",
    )

    # --- Extraction quality signals (`BLUEPRINT.md` §14.3) ---
    min_non_whitespace_chars: int = Field(
        default=20,
        ge=0,
        description="Absolute floor: below this many non-whitespace "
        "characters, a page is treated as needing OCR regardless of its "
        "computed score (guards against density being misleading on an "
        "unusually small page).",
    )
    target_char_density_per_sq_inch: float = Field(
        default=0.3,
        gt=0,
        description="Non-whitespace characters per square inch of page area "
        "at which the density component of the quality score saturates to "
        "1.0. Deliberately close to `min_non_whitespace_chars`' own implied "
        "density on a Letter/A4 page (~0.2/sq in): a legitimately sparse "
        "page (a short cover line, an invoice header) must not be misrouted "
        "to OCR just for being sparse. Known, accepted limitation: this "
        "means an image-heavy page with a small genuine text caption is "
        "*not* caught by density alone and is treated as text-sufficient — "
        "catching that would need visual/rendering analysis, which this "
        "text-only signal cannot do. See docs/handoff/019-pdf-text-extraction.md.",
    )
    max_bad_char_ratio: float = Field(
        default=0.05,
        gt=0,
        le=1.0,
        description="Fraction of extracted characters that are the Unicode "
        "replacement character or a disallowed control character, above "
        "which the cleanliness score reaches 0.0. A well-encoded PDF should "
        "be near 0%; pypdf's known weak spot on non-standard glyph mappings "
        "(ADR 0008) is what this catches.",
    )
    min_recognizable_word_ratio: float = Field(
        default=0.35,
        gt=0,
        le=1.0,
        description="Fraction of whitespace-separated tokens that look like "
        "real words, at which the word-shape score saturates to 1.0. Set "
        "low because fiscal documents are expected to carry many numeric/"
        "code tokens (amounts, dates, IBANs) that are not 'words'.",
    )
    min_quality_score: float = Field(
        default=0.5,
        ge=0,
        le=1.0,
        description="The final gate: a page below this combined score needs "
        "OCR. The three component scores are multiplied together, so no "
        "single badly-failing signal can be masked by the other two.",
    )
