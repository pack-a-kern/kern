/**
 * A dialog or sheet opens with focus on itself, not on its first field.
 *
 * Radix focuses the first focusable element by default, and on a phone that is a field: the keyboard
 * came up over a dialog the person had only opened to read. Focus still moves into the dialog — the
 * trap, Escape and Tab all work from there — it just lands on the panel instead of an input.
 */
export function focusPanel(event: Event): void {
    event.preventDefault();

    if (event.currentTarget instanceof HTMLElement) {
        event.currentTarget.focus();
    }
}
