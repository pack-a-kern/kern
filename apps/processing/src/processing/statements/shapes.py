"""What a column's values *look like*, without saying what any of them is.

## Why this exists

Matching a statement's columns to the fields a transaction needs is the one step a machine cannot do
alone: banks name the same column "Data", "Data tranzactie", "Booking date" or nothing at all. A
model is very good at it — but sending it the statement means sending every counterparty name and
amount to a third party.

A shape is the compromise. `date DD.MM.YYYY`, `number -1.234,56`, `text ~46 chars` describe the
column precisely enough to recognise it, and describe no transaction at all. Nothing that leaves
this function can identify a person, a payment or a balance.
"""

import re
from collections import Counter

_DATE_PATTERNS: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("DD.MM.YYYY", re.compile(r"^\d{1,2}\.\d{1,2}\.\d{4}$")),
    ("DD/MM/YYYY", re.compile(r"^\d{1,2}/\d{1,2}/\d{4}$")),
    ("DD-MM-YYYY", re.compile(r"^\d{1,2}-\d{1,2}-\d{4}$")),
    ("YYYY-MM-DD", re.compile(r"^\d{4}-\d{1,2}-\d{1,2}$")),
    ("YYYY/MM/DD", re.compile(r"^\d{4}/\d{1,2}/\d{1,2}$")),
    ("DD.MM.YY", re.compile(r"^\d{1,2}\.\d{1,2}\.\d{2}$")),
    # "12 septembrie 2026". The month is matched as a word in any language rather than against a
    # list of names: the shape is the same in Romanian, English or Hungarian, and a reader that only
    # knew one language would read the same column as text for every other bank.
    ("D MONTH YYYY", re.compile(r"^\d{1,2}\s+[^\W\d_]{3,12}\.?\s+\d{4}$", re.UNICODE)),
    # A date with a time after it is still a date column; the time is noise a parser drops.
    ("DD.MM.YYYY HH:MM", re.compile(r"^\d{1,2}\.\d{1,2}\.\d{4}[ T]\d{1,2}:\d{2}")),
    ("YYYY-MM-DD HH:MM", re.compile(r"^\d{4}-\d{1,2}-\d{1,2}[ T]\d{1,2}:\d{2}")),
)

_CURRENCY = re.compile(r"^[A-Z]{3}$")

# A number as a statement writes it: an optional sign, digits with optional group separators, and an
# optional fractional part. Both European (1.234,56) and English (1,234.56) conventions, plus the
# trailing-minus some exports use.
_NUMBER = re.compile(
    r"^[-+(]?\s*\d{1,3}([.,\s']\d{3})*([.,]\d{1,4})?\s*[-)]?$|^[-+]?\d+([.,]\d{1,4})?-?$"
)


def _number_shape(value: str) -> str | None:
    """`number -1.234,56` and friends: the separators as this column writes them."""
    if not _NUMBER.match(value.strip()):
        return None

    digits = value.strip()
    negative = digits.startswith("-") or digits.endswith("-") or digits.startswith("(")
    body = digits.strip("+-() ")

    decimal = ""
    thousands = ""

    # The last separator with 1-2 following digits is the decimal one; anything else separating
    # groups of three is a thousands separator. "1.234" alone is ambiguous and stays a plain number.
    match = re.search(r"([.,])(\d{1,4})$", body)

    if match is not None and len(match.group(2)) <= 2:
        decimal = match.group(1)
        head = body[: match.start()]
    else:
        head = body

    group = re.search(r"([.,\s'])\d{3}", head)

    if group is not None:
        thousands = group.group(1)

    if decimal == "" and thousands == "":
        return f"number {'-' if negative else ''}1234"

    sample = "1" + (thousands or "") + "234" + (decimal + "56" if decimal else "")

    return f"number {'-' if negative else ''}{sample}"


def shape_of(values: list[str]) -> str:
    """The shape of a column, from the values that are actually filled in.

    The most common shape wins rather than the first: a date column with one stray "n/a" is still
    a date column, and a description that happens to start with a number is not a number column.
    """
    filled = [value.strip() for value in values if value.strip() != ""]

    if not filled:
        return "empty"

    shapes: Counter[str] = Counter()

    for value in filled:
        matched = next(
            (f"date {name}" for name, pattern in _DATE_PATTERNS if pattern.match(value)), None
        )

        if matched is None:
            matched = _number_shape(value)

        if matched is None and _CURRENCY.match(value):
            matched = "currency code"

        shapes[matched or "text"] += 1

    shape, count = shapes.most_common(1)[0]

    if shape == "text":
        average = round(sum(len(value) for value in filled) / len(filled))

        return f"text ~{average} chars"

    # A column that is only *mostly* one thing says so, because that is exactly when a person should
    # look at the preview before trusting the mapping.
    return shape if count == len(filled) else f"{shape} (mostly)"
