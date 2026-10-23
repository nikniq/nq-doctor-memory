<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Support\Daily;
use App\Support\TurnOdds;
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

        $players = $data['players'] ?? 1;
        $isRankedDaily = $isDaily && $players === 1;

        $game = Game::create([
            'user_id' => $owner?->id,
            'difficulty' => $data['difficulty'],
            'played_on' => $isDaily ? $today : null,
            'players' => $players,
            'pairs' => $grids[$data['difficulty']]['pairs'],
            'started_at' => now(),
            'attempt' => $isRankedDaily ? $this->dailyGamesStarted($request, $owner?->id, $data['difficulty'], $today) + 1 : null,
        ]);

        if ($isRankedDaily && $owner === null) {
            $request->session()->push('daily_games', $game->id);
        }

        return response()->json([
            'token' => $game->id,
            'account' => $owner?->name,
            'attempt' => $game->attempt,
        ], 201);
    }

    /**
     * Solo games this player has already STARTED on today's board for a grid.
     * Started, not finished: otherwise abandoning a bad start and dealing
     * again would give a fresh first attempt. Accounts are tracked by user;
     * guests by their session, which is the best a guest can be told apart.
     */
    private function dailyGamesStarted(Request $request, ?int $userId, string $grid, string $day): int
    {
        $query = Game::query()
            ->where('players', 1)
            ->where('difficulty', $grid)
            ->whereDate('played_on', $day);

        if ($userId !== null) {
            return $query->where('user_id', $userId)->count();
        }

        $ids = (array) $request->session()->get('daily_games', []);

        return $ids === [] ? 0 : $query->whereIn('id', $ids)->count();
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
        $game->update(['unranked_reason' => $this->unrankedReason($game, $pairs)]);

        return response()->json([
            'turns' => $game->turns,
            'seconds' => $game->seconds,
            'daily' => $this->dailyStanding($game),
            'ranked' => $game->isRanked(),
            'reason' => $game->unranked_reason,
            'message' => $game->unrankedMessage(),
            'scorable' => $game->players === 1 && $game->isRanked(),
        ]);
    }

    /**
     * Where a ranked solo daily game stands among today's players on that
     * board: how many there are (including this one) and how many it beat.
     *
     * @return array{number: int, players: int, beaten: int}|null
     */
    private function dailyStanding(Game $game): ?array
    {
        if (! $game->isDaily() || $game->players !== 1 || ! $game->isRanked()) {
            return null;
        }

        $date = $game->played_on->toDateString();
        $board = fn () => Daily::rankedGames($date, $game->difficulty);

        return [
            'number' => Daily::number($date),
            'players' => $board()->count(),
            'beaten' => $board()
                ->whereKeyNot($game->id)
                ->where(fn ($query) => $query
                    ->where('turns', '>', $game->turns)
                    ->orWhere(fn ($tie) => $tie->where('turns', $game->turns)->where('seconds', '>', $game->seconds)))
                ->count(),
        ];
    }

    /**
     * Why a finished game should not count, or null when it should.
     *
     * Implausible: a perfect-memory player would do this well less often
     * than config('game.implausible_odds'), or the time is shorter than the
     * misses allow (each miss locks the board for 0.9 s).
     */
    private function unrankedReason(Game $game, int $pairs): ?string
    {
        $misses = $game->turns - $pairs;
        $tooLucky = TurnOdds::atMost($pairs, $game->turns) < config('game.implausible_odds');
        $tooFast = $game->seconds + self::CLOCK_TOLERANCE_SECONDS < 0.9 * $misses;

        if ($tooLucky || $tooFast) {
            return Game::IMPLAUSIBLE;
        }

        return ($game->attempt ?? 1) > 1 ? Game::PRACTICE : null;
    }
}
