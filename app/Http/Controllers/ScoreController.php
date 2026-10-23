<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Score;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScoreController extends Controller
{
    /**
     * Top scores for one board (grid, free play or today's daily challenge).
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'difficulty' => ['required', Rule::in(array_keys(config('game.difficulties')))],
            'daily' => ['sometimes', 'boolean'],
        ]);

        $playedOn = $request->boolean('daily') ? now()->toDateString() : null;

        $scores = Score::query()
            ->forBoard($data['difficulty'], $playedOn)
            ->ranked()
            ->limit(config('game.leaderboard_size'))
            ->with('user:id,name')
            ->get(['id', 'user_id', 'player_name', 'moves', 'seconds', 'created_at'])
            ->map(fn (Score $score) => [
                'id' => $score->id,
                // Registered rows follow the account's current name.
                'player_name' => $score->user?->name ?? $score->player_name,
                'moves' => $score->moves,
                'seconds' => $score->seconds,
                'registered' => $score->user_id !== null,
            ]);

        return response()->json(['data' => $scores, 'played_on' => $playedOn]);
    }

    /**
     * Put a finished solo game on the leaderboard. Turns and time come from
     * the server's record of the game, not from this request.
     *
     * Verified players post under their account name; everyone else picks one.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isVerifiedPlayer = $user?->hasVerifiedEmail() ?? false;

        $data = $request->validate([
            'game' => ['required', 'uuid'],
            'player_name' => [Rule::requiredIf(! $isVerifiedPlayer), 'nullable', 'string', 'max:24'],
        ]);

        $game = Game::find($data['game']);

        if (! $game || ($game->user_id !== null && $game->user_id !== $user?->id)) {
            throw ValidationException::withMessages(['game' => 'Start a game before submitting a score.']);
        }

        if (! $game->isFinished()) {
            throw ValidationException::withMessages(['game' => 'Finish the game before submitting a score.']);
        }

        if ($game->isScored()) {
            throw ValidationException::withMessages(['game' => 'This game has already been scored.']);
        }

        if ($game->players !== 1) {
            throw ValidationException::withMessages(['game' => 'Two-player games are not ranked.']);
        }

        if (! $game->isRanked()) {
            throw ValidationException::withMessages(['game' => $game->unrankedMessage()]);
        }

        if ($game->isDaily() && ! $game->played_on->isToday()) {
            throw ValidationException::withMessages(['game' => 'That daily challenge has ended.']);
        }

        $outcome = DB::transaction(function () use ($game, $data, $user, $isVerifiedPlayer) {
            // Claim the game first, so overlapping submissions cannot both pass.
            $claimed = Game::whereKey($game->id)
                ->whereNotNull('finished_at')
                ->whereNull('scored_at')
                ->update(['scored_at' => now()]);

            if ($claimed === 0) {
                throw ValidationException::withMessages(['game' => 'This game has already been scored.']);
            }

            $candidate = new Score([
                'user_id' => $isVerifiedPlayer ? $user->id : null,
                'player_name' => $isVerifiedPlayer ? $user->name : trim($data['player_name']),
                'difficulty' => $game->difficulty,
                'played_on' => $game->played_on?->toDateString(),
                'moves' => $game->turns,
                'seconds' => $game->seconds,
            ]);

            // Verified players hold one row per board, replaced only by a better game.
            $existing = $isVerifiedPlayer
                ? Score::query()
                    ->forBoard($game->difficulty, $game->played_on?->toDateString())
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($existing && ! $this->beats($candidate, $existing)) {
                Game::whereKey($game->id)->update(['score_id' => $existing->id]);

                return ['score' => $existing, 'improved' => false];
            }

            $existing?->delete();
            $candidate->save();
            Game::whereKey($game->id)->update(['score_id' => $candidate->id]);

            return ['score' => $candidate, 'improved' => true];
        });

        $score = $outcome['score'];
        $rank = Score::query()
            ->forBoard($score->difficulty, $score->played_on?->toDateString())
            ->betterThan($score)
            ->count() + 1;

        return response()->json([
            'data' => Arr::only($score->toArray(), ['id', 'player_name', 'difficulty', 'played_on', 'moves', 'seconds']),
            'rank' => $rank,
            'improved' => $outcome['improved'],
        ], $outcome['improved'] ? 201 : 200);
    }

    /**
     * Fewer turns wins; equal turns fall back to the faster time.
     */
    private function beats(Score $candidate, Score $existing): bool
    {
        if ($candidate->moves !== $existing->moves) {
            return $candidate->moves < $existing->moves;
        }

        return $candidate->seconds < $existing->seconds;
    }
}
