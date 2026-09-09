"""Per-page extraction-quality scoring — the measurable signals behind the
OCR decision (`BLUEPRINT.md` §14.3).

Four signals, none of them a vibe:

1. **Non-whitespace character count** — an absolute floor. A page with
   almost no extractable text almost certainly has no usable text layer,
   independent of page size.
2. **Character density relative to page area** — normalizes the absolute
   floor to page size, because a caption can be "enough characters" on an
   unusually small page, or "not enough" on an unusually large one. The
   saturation point is deliberately low (see `DocumentExtractionSettings.
   target_char_density_per_sq_inch`) so a legitimately sparse page is not
   misrouted to OCR just for being sparse. Known, accepted limitation: an
   image-heavy page with a small genuine text caption is *not* caught by
   this signal — distinguishing "sparse but complete" from "mostly image
   with a caption" needs visual/rendering analysis, which a text-only
   signal cannot do.
3. **Replacement/control character ratio** — catches the extraction-quality
   failure `docs/adr/0008-permissive-pdf-extraction.md` documents honestly
   as `pypdf`'s known weak spot: non-standard glyph-to-Unicode mappings on
   complex layouts can come back garbled rather than empty.
4. **Recognizable word ratio** — catches gibberish that is neither garbled
   encoding nor a page-density problem: text that "looks like characters"
   token-by-token but does not look like language.

See `docs/handoff/019-pdf-text-extraction.md` for the exact thresholds and
why each one was chosen; all of them live in `DocumentExtractionSettings`,
never hard-coded here.
"""

import re
import unicodedata

from processing.documents.config import DocumentExtractionSettings
from processing.documents.schemas import PageQualitySignals

_REPLACEMENT_CHAR = "\ufffd"
_ALLOWED_CONTROL_CHARS = frozenset({"\n", "\t", "\r"})
# Unicode-aware "looks like a word": at least two letters, no digits/underscore.
# Deliberately not stricter than this — Romanian diacritics, mixed-case
# abbreviations ("S.R.L" minus the dots) and similar must count as words.
_WORD_PATTERN = re.compile(r"^[^\W\d_]{2,}$")


def compute_quality_signals(text: str, page_area_sq_inches: float) -> PageQualitySignals:
    """Computes every measurable signal for one page's extracted text.

    `page_area_sq_inches` comes from the page's MediaBox (PDF points are
    1/72 inch) — see `processing.documents.extraction`.
    """
    non_whitespace_count = sum(1 for char in text if not char.isspace())
    total_count = len(text)
    bad_chars = sum(1 for char in text if _is_bad_char(char))
    bad_char_ratio = bad_chars / total_count if total_count else 0.0
    tokens = text.split()
    recognizable = sum(1 for token in tokens if _WORD_PATTERN.match(token))
    word_ratio = recognizable / len(tokens) if tokens else 0.0
    density = non_whitespace_count / page_area_sq_inches if page_area_sq_inches > 0 else 0.0
    return PageQualitySignals(
        non_whitespace_char_count=non_whitespace_count,
        total_char_count=total_count,
        page_area_sq_inches=page_area_sq_inches,
        char_density_per_sq_inch=density,
        bad_char_ratio=bad_char_ratio,
        recognizable_word_ratio=word_ratio,
        is_whitespace_only=non_whitespace_count == 0,
    )


def _is_bad_char(char: str) -> bool:
    if char == _REPLACEMENT_CHAR:
        return True
    return char not in _ALLOWED_CONTROL_CHARS and unicodedata.category(char) == "Cc"


def compute_quality_score(
    signals: PageQualitySignals, settings: DocumentExtractionSettings
) -> float:
    """Combines the four signals into one score in `[0, 1]`.

    The three non-trivial components are multiplied, not averaged: a page
    that is dense and clean but full of word-shaped gibberish (or vice
    versa) must not be masked by the other two scoring well — one bad
    dimension pulls the whole score down.
    """
    if signals.is_whitespace_only:
        return 0.0
    density_score = min(
        1.0, signals.char_density_per_sq_inch / settings.target_char_density_per_sq_inch
    )
    cleanliness_score = max(0.0, 1.0 - signals.bad_char_ratio / settings.max_bad_char_ratio)
    word_score = min(1.0, signals.recognizable_word_ratio / settings.min_recognizable_word_ratio)
    return density_score * cleanliness_score * word_score


def decide_needs_ocr(
    signals: PageQualitySignals, quality_score: float, settings: DocumentExtractionSettings
) -> bool:
    """The routing decision: OCR is needed if the combined score is below
    the configured gate, or the absolute non-whitespace-character floor is
    not met (guards against density looking acceptable on a tiny page)."""
    return (
        quality_score < settings.min_quality_score
        or signals.non_whitespace_char_count < settings.min_non_whitespace_chars
    )
