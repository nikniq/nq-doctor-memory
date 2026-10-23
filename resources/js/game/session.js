/**
 * Pure helpers for talking to the game-session endpoints.
 */

export const CONNECTIVITY_MESSAGE = 'Could not reach the server to record this game, so the score cannot be saved.';

/** True when the server's daily date is a different day from the one the board was dealt for. */
export function dailyDateChanged(today, playedOn) {
    return Boolean(playedOn) && playedOn !== today;
}

/** The first validation message in a Laravel JSON error body, or null. */
export function firstErrorMessage(body) {
    const errors = body && typeof body === 'object' ? body.errors : null;
    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat()[0];
        if (first) return String(first);
    }
    return body && typeof body.message === 'string' && body.message ? body.message : null;
}

/**
 * Turn a failed response into an Error. A 422 carries the server's own
 * explanation and is final; anything else may be a passing outage, so it is
 * marked retryable.
 */
export function requestError(status, body) {
    if (status === 422) {
        const error = new Error(firstErrorMessage(body) ?? 'The server rejected this game.');
        error.retryable = false;
        error.errors = body?.errors ?? {};
        return error;
    }
    const error = new Error(CONNECTIVITY_MESSAGE);
    error.retryable = true;
    return error;
}
