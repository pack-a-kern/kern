"""Extraction-quality signal/score/decision unit tests — pure functions, no
PDF parsing, no filesystem, no clock (`docs/CODE-QUALITY.md` §5)."""

from processing.documents.config import DocumentExtractionSettings
from processing.documents.quality import (
    compute_quality_score,
    compute_quality_signals,
    decide_needs_ocr,
)

_LETTER_AREA_SQ_IN = (612.0 / 72.0) * (792.0 / 72.0)  # ~93.5 sq in


def test_empty_text_is_whitespace_only_and_scores_zero() -> None:
    settings = DocumentExtractionSettings()
    signals = compute_quality_signals("", _LETTER_AREA_SQ_IN)

    assert signals.is_whitespace_only
    assert signals.non_whitespace_char_count == 0
    assert compute_quality_score(signals, settings) == 0.0
    assert decide_needs_ocr(signals, 0.0, settings)


def test_whitespace_only_text_is_whitespace_only_and_scores_zero() -> None:
    settings = DocumentExtractionSettings()
    signals = compute_quality_signals("   \n\n\t  \n", _LETTER_AREA_SQ_IN)

    assert signals.is_whitespace_only
    assert compute_quality_score(signals, settings) == 0.0
    assert decide_needs_ocr(signals, 0.0, settings)


def test_dense_normal_prose_scores_the_maximum_and_does_not_need_ocr() -> None:
    settings = DocumentExtractionSettings()
    text = "Aceasta este o pagina normala de text cu propozitii intregi si cuvinte reale. " * 10
    signals = compute_quality_signals(text, _LETTER_AREA_SQ_IN)
    score = compute_quality_score(signals, settings)

    assert score == 1.0
    assert not decide_needs_ocr(signals, score, settings)


def test_a_high_replacement_character_ratio_drives_the_score_to_zero() -> None:
    settings = DocumentExtractionSettings()
    text = "�" * 200 + "real words here" * 5
    signals = compute_quality_signals(text, _LETTER_AREA_SQ_IN)
    score = compute_quality_score(signals, settings)

    assert signals.bad_char_ratio > settings.max_bad_char_ratio
    assert score == 0.0
    assert decide_needs_ocr(signals, score, settings)


def test_a_small_replacement_character_ratio_barely_dents_the_score() -> None:
    """A ligature-substitution edge case now and then should not, by
    itself, force a page into OCR."""
    settings = DocumentExtractionSettings()
    body = "text real cu cuvinte normale " * 20
    text = body + "�"  # one bad character among hundreds
    signals = compute_quality_signals(text, _LETTER_AREA_SQ_IN)
    score = compute_quality_score(signals, settings)

    assert signals.bad_char_ratio < settings.max_bad_char_ratio
    assert not decide_needs_ocr(signals, score, settings)


def test_pure_control_characters_other_than_tab_newline_cr_count_as_bad() -> None:
    settings = DocumentExtractionSettings()
    text = "\x0b\x0c\x0e" * 100  # vertical tab, form feed, shift-out: Cc, not allowed
    signals = compute_quality_signals(text, _LETTER_AREA_SQ_IN)

    assert signals.bad_char_ratio == 1.0
    assert compute_quality_score(signals, settings) == 0.0


def test_tab_newline_and_carriage_return_are_not_counted_as_bad_characters() -> None:
    settings = DocumentExtractionSettings()
    text = ("word\tword\nword\r" * 50) + "more real words to pad the page out nicely" * 5
    signals = compute_quality_signals(text, _LETTER_AREA_SQ_IN)

    assert signals.bad_char_ratio == 0.0
    assert compute_quality_score(signals, settings) > 0.0


def test_a_page_of_only_numbers_has_zero_recognizable_word_ratio() -> None:
    """Documented, accepted limitation: a legitimately numeric-only page
    (an amounts table) is indistinguishable from gibberish by this signal
    alone and is routed to OCR — see docs/handoff/019-pdf-text-extraction.md."""
    settings = DocumentExtractionSettings()
    text = "1234,56 78901,23 456,00 " * 50
    signals = compute_quality_signals(text, _LETTER_AREA_SQ_IN)
    score = compute_quality_score(signals, settings)

    assert signals.recognizable_word_ratio == 0.0
    assert score == 0.0
    assert decide_needs_ocr(signals, score, settings)


def test_a_realistic_mix_of_words_and_numbers_scores_above_the_word_ratio_floor() -> None:
    """Fiscal documents are expected to carry heavy numeric/code content;
    `min_recognizable_word_ratio` is calibrated low enough that a normal
    invoice-shaped mix still saturates the word-ratio component."""
    settings = DocumentExtractionSettings()
    text = (
        "Factura seria ABC nr 123 din data 07 09 2026 client SC Exemplu SRL "
        "suma 1234 56 RON cota TVA 19 total plata 1468 92 RON "
    ) * 6
    signals = compute_quality_signals(text, _LETTER_AREA_SQ_IN)

    assert signals.recognizable_word_ratio >= settings.min_recognizable_word_ratio


def test_a_short_but_real_line_of_text_does_not_need_ocr() -> None:
    """The density threshold must not punish a legitimately sparse page
    (a short cover line, a one-line invoice) just for being short."""
    settings = DocumentExtractionSettings()
    signals = compute_quality_signals("Factura 001, suma 50 RON.", _LETTER_AREA_SQ_IN)
    score = compute_quality_score(signals, settings)

    assert not decide_needs_ocr(signals, score, settings)


def test_the_same_sparse_text_on_a_tiny_page_can_still_be_gated_by_the_absolute_floor() -> None:
    """`min_non_whitespace_chars` exists specifically because density can
    look artificially fine on an unusually small page; a page area so
    small it cannot exist in practice must not let a handful of characters
    pass purely on density."""
    settings = DocumentExtractionSettings(min_non_whitespace_chars=50)
    text = "short"
    signals = compute_quality_signals(text, page_area_sq_inches=0.001)

    assert signals.non_whitespace_char_count < settings.min_non_whitespace_chars
    score = compute_quality_score(signals, settings)
    assert decide_needs_ocr(signals, score, settings)


def test_zero_area_page_has_zero_density_rather_than_dividing_by_zero() -> None:
    settings = DocumentExtractionSettings()
    signals = compute_quality_signals("some text", page_area_sq_inches=0.0)

    assert signals.char_density_per_sq_inch == 0.0
    compute_quality_score(signals, settings)  # must not raise ZeroDivisionError


def test_score_is_never_negative_even_with_an_extreme_bad_char_ratio() -> None:
    settings = DocumentExtractionSettings()
    signals = compute_quality_signals("�" * 1000, _LETTER_AREA_SQ_IN)
    score = compute_quality_score(signals, settings)

    assert score == 0.0
