<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GameSessionController extends Controller
{
    /**
     * Seconds of slack allowed between the client's timer and the server's.
     * The client's clock pauses while the tab is hidden, so it can only ever
     * be shorter than the server's; the slack covers request latency.
     */
    public const CLOCK_TOLERANCE_SECONDS = 3;

    /**
     * Register the start of a game. The returned token identifies it when
     * it finishes and when a score is submitted.
     */
    public function store(Request $request): JsonResponse
    {
        $grids = config('game.difficulties');

        $data = $request->validate([
            'difficulty' => ['required', Rule::in(array_keys($grids))],
            'daily' => ['sometimes', 'boolean'],
            'players' => ['sometimes', 'integer', Rule::in([1, 2])],
        ]);

        $game = Game::create([
            'user_id' => $request->user()?->id,
            'difficulty' => $data['difficulty'],
            'played_on' => $request->boolean('daily') ? now()->toDateString() : null,
            'players' => $data['players'] ?? 1,
            'pairs' => $grids[$data['difficulty']]['pairs'],
            'started_at' => now(),
        ]);

        return response()->json(['token' => $game->id], 201);
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

        // A game cannot be won in fewer turns than it has pairs.
        if ($data['turns'] < $game->pairs) {
            throw ValidationException::withMessages(['turns' => "Turns must be at least {$game->pairs}."]);
        }

        if ($data['seconds'] > $game->serverElapsedSeconds() + self::CLOCK_TOLERANCE_SECONDS) {
            throw ValidationException::withMessages(['seconds' => 'That time does not match the server clock.']);
        }

        $game->update([
            'turns' => $data['turns'],
            'seconds' => $data['seconds'],
            'finished_at' => now(),
        ]);

        return response()->json([
            'turns' => $game->turns,
            'seconds' => $game->seconds,
            'scorable' => $game->players === 1,
        ]);
    }
}
