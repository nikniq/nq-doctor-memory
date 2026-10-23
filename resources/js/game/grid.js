/**
 * Where focus should move in a grid of `count` cells laid out in `columns`
 * columns for a navigation key. Returns null for keys it does not handle.
 * Movement stops at the edges rather than wrapping.
 */
export function nextIndex(index, key, columns, count) {
    const last = count - 1;
    switch (key) {
        case 'ArrowRight':
            return Math.min(index + 1, last);
        case 'ArrowLeft':
            return Math.max(index - 1, 0);
        case 'ArrowDown':
            return index + columns <= last ? index + columns : index;
        case 'ArrowUp':
            return index - columns >= 0 ? index - columns : index;
        case 'Home':
            return 0;
        case 'End':
            return last;
        default:
            return null;
    }
}
