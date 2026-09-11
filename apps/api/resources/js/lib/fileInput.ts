/**
 * Reading a file picker's selection safely.
 *
 * A file `<input>` has to be reset after each pick, or choosing the *same* file twice fires no
 * second `change` event and the second attempt looks broken. The trap is that `input.files` is a
 * **live** reference, not a snapshot: assigning `input.value = ''` empties the very list a caller is
 * still holding.
 *
 *     const files = input.files;   // FileList(1)
 *     input.value = '';
 *     files.length;                // 0 — in Chrome, verified, not assumed
 *
 * That is not a hypothetical. It is why picking a document silently did nothing: the loop that
 * queued each file iterated an array that had just been emptied, so nothing was enqueued, nothing
 * was uploaded, and no error was raised to show for it.
 *
 * Copying first and resetting second is the whole fix, and it is a function rather than two lines at
 * the call site so the order cannot be quietly swapped back.
 */
export function takeSelectedFiles(input: HTMLInputElement): File[] {
    // Materialised into a real array *before* the reset below — this is the load-bearing line.
    const selected = Array.from(input.files ?? []);

    input.value = '';

    return selected;
}
