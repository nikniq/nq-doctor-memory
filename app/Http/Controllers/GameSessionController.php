<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Server-side record of each game: started when the first card is flipped,
 * finished with the turn count and time the client reports.
 *
 * Reported times are clamped to the server's own clock plus a small
 * tolerance rather than rejected. The client's timer starts on the first
 * flip, slightly before the start request reaches the server, and pauses
 * while the tab is hidden, so an honest time can only be shorter than the
 * server's; a slow start request must not cost a player their game.
 */
class GameSessionController extends Controller
{
    /**
     * Seconds of slack allowed above the server's elapsed time.
     */
    public const CLOCK_TOLERANCE_SECONDS = 3;

    /**
     * Register the start of a game. The returned token identifies it when it
     * finishes and when a score is submitted; `account` names the verified
     * account the game will be saved to, or is null.
     */
    public function store(Request $request): JsonResponse
    {
        $grids = config('game.difficulties');

        $data = $request->validate([
            'difficulty' => ['required', Rule::in(array_keys($grids))],
            'daily' => ['sometimes', 'boolean'],
            'players' => ['sometimes', 'integer', Rule::in([1, 2])],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $isDaily = $request->boolean('daily');
        $today = now()->toDateString();

        // The client deals the daily board from the date it was given at page
        // load. If that day has ended, the board it shows is yesterday's.
        // TODO: require `date` for daily games once no tabs run the old script.
        if ($isDaily && isset($data['date']) && $data['date'] !== $today) {
            throw ValidationException::withMessages(['date' => 'A new daily challenge has started. Reload to play it.']);
        }

        $user = $request->user();
        $owner = $user?->hasVerifiedEmail() ? $user : null;

        $game = Game::create([
            'user_id' => $owner?->id,
            'difficulty' => $data['difficulty'],
            'played_on' => $isDaily ? $today : null,
            'players' => $data['players'] ?? 1,
            'pairs' => $grids[$data['difficulty']]['pairs'],
            'started_at' => now(),
        ]);

        return response()->json(['token' => $game->id, 'account' => $owner?->name], 201);
    }

    /**
     * Record that a game was completed and how many turns it took.
     */
    public function finish(Request $request, Game $game): JsonResponse
    {
        // A game started while signed in can only be finished by that account.
        abort_if($game->user_id !== null && $game->user_id !== $request->user()?->id, 404);

        if ($game->isFinished()) {
            throw ValidationException::withMessages(['game' => 'This game has already finished.']);
        }

        $data = $request->validate([
            'turns' => ['required', 'integer', 'min:1', 'max:10000'],
            'seconds' => ['required', 'integer', 'min:1', 'max:86400'],
        ]);

        // Games started before the pair count was stored have pairs = 0.
        $pairs = max((int) $game->pairs, (int) config("game.difficulties.{$game->difficulty}.pairs", 1));

        // A game cannot be won in fewer turns than it has pairs.
        if ($data['turns'] < $pairs) {
            throw ValidationException::withMessages(['turns' => "Turns must be at least {$pairs}."]);
        }

        $seconds = min($data['seconds'], max(1, $game->serverElapsedSeconds() + self::CLOCK_TOLERANCE_SECONDS));

        // Conditional update so two overlapping finish requests cannot both win.
        $updated = Game::whereKey($game->id)
            ->whereNull('finished_at')
            ->update(['turns' => $data['turns'], 'seconds' => $seconds, 'finished_at' => now()]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['game' => 'This game has already finished.']);
        }

        $game->refresh();

        return response()->json([
            'turns' => $game->turns,
            'seconds' => $game->seconds,
            'scorable' => $game->players === 1,
        ]);
    }
}
