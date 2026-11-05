<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Show the memory game board.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('game', [
            'title' => config('game.title'),
            'winMessage' => config('game.win_message'),
            'fontFamily' => config('game.font_family'),
            'fontSize' => config('game.font_size'),
            'colors' => config('game.colors'),
            'cards' => config('game.cards'),
            'difficulties' => config('game.difficulties'),
            'defaultDifficulty' => config('game.default_difficulty'),
            'today' => now()->toDateString(),
            'gamesUrl' => route('games.store'),
            'playerName' => $user?->hasVerifiedEmail() ? $user->name : null,
            'dailyPlayed' => $this->dailyGridsStarted($request),
            'accountBests' => $this->accountBests($request),
            // Deep links (?mode=daily&grid=6x6) from shared results; bad values are ignored.
            'initialMode' => in_array($request->query('mode'), ['daily', 'free'], true) ? $request->query('mode') : null,
            'initialGrid' => is_string($request->query('grid')) && array_key_exists($request->query('grid'), config('game.difficulties'))
                ? $request->query('grid')
                : null,
        ]);
    }

    /**
     * A verified player's best ranked solo game per board, so "Your best"
     * follows them across devices. Keys match the page's local-storage keys:
     * "best:<grid>" for free play, "best:daily:<date>:<grid>" for today's daily.
     *
     * @return array<string, array{moves: int, seconds: int}>
     */
    private function accountBests(Request $request): array
    {
        $user = $request->user();
        if (! $user?->hasVerifiedEmail()) {
            return [];
        }

        $today = now()->toDateString();

        return $user->games()
            ->finished()
            ->ranked()
            ->where('players', 1)
            ->where(fn ($query) => $query->whereNull('played_on')->orWhereDate('played_on', $today))
            ->orderBy('turns')
            ->orderBy('seconds')
            ->get(['difficulty', 'played_on', 'turns', 'seconds'])
            ->reduce(function (array $bests, $game) {
                $key = $game->played_on ? "best:daily:{$game->played_on->toDateString()}:{$game->difficulty}" : "best:{$game->difficulty}";
                $bests[$key] ??= ['moves' => $game->turns, 'seconds' => $game->seconds];

                return $bests;
            }, []);
    }

    /**
     * Grids whose solo daily board this player has already started today,
     * so the page can say that another go is practice.
     *
     * @return list<string>
     */
    private function dailyGridsStarted(Request $request): array
    {
        $user = $request->user();
        $query = Game::query()->where('players', 1)->whereDate('played_on', now()->toDateString());

        if ($user?->hasVerifiedEmail()) {
            $query->where('user_id', $user->id);
        } else {
            $ids = (array) $request->session()->get('daily_games', []);
            if ($ids === []) {
                return [];
            }
            $query->whereIn('id', $ids);
        }

        return $query->distinct()->orderBy('difficulty')->pluck('difficulty')->all();
    }
}
