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
        ]);
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
