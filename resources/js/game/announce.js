/**
 * Text for screen readers: card labels and what happened on each turn.
 */

/** 1-based row and column of a card in a grid with `columns` columns. */
export function positionOf(index, columns) {
    return { row: Math.floor(index / columns) + 1, col: (index % columns) + 1 };
}

/** "Row 2, column 3, face down" / "…, Stethoscope" / "…, Stethoscope, matched". */
export function cardLabel(card, index, columns) {
    const { row, col } = positionOf(index, columns);
    const where = `Row ${row}, column ${col}`;
    if (card.isMatched) return `${where}, ${card.name}, matched`;
    if (card.isFlipped) return `${where}, ${card.name}`;
    return `${where}, face down`;
}

/** What a turn did, e.g. "Match: Stethoscope. 3 of 8 pairs. Turn 5." */
export function turnMessage({ matched, a, b, pairsFound, pairs, turn, players = 1, nextPlayer = 1, fact = '' }) {
    let message = matched
        ? `Match: ${a}. ${pairsFound} of ${pairs} pairs. Turn ${turn}.`
        : `No match: ${a} and ${b}. Turn ${turn}.`;
    if (players === 2) message += ` Player ${nextPlayer}'s turn.`;
    if (matched && fact) message += ` ${fact}`;
    return message;
}

/** The end of a game. */
export function winMessage(result) {
    if (result.players === 2) {
        const [p1, p2] = result.playerScores;
        const outcome = p1 === p2 ? "It's a tie" : `Player ${p1 > p2 ? 1 : 2} wins`;
        return `All pairs found. ${outcome}, ${p1} to ${p2}.`;
    }
    return `All pairs found in ${result.moves} turns. ${result.stars} of 3 stars.`;
}
