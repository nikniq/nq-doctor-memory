<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * The signed-in player's captured games: per-grid records and history.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $gridStats = $user->games()
            ->finished()
            ->ranked()
            ->where('players', 1)
            ->selectRaw('difficulty, COUNT(*) as games_played, MIN(turns) as best_turns, AVG(turns) as average_turns, MIN(seconds) as best_seconds')
            ->groupBy('difficulty')
            ->get()
            ->keyBy('difficulty');

        $recentGames = $user->games()
            ->finished()
            ->latest('finished_at')
            ->limit(15)
            ->get();

        return view('account', [
            'user' => $user,
            'grids' => config('game.difficulties'),
            'gridStats' => $gridStats,
            'recentGames' => $recentGames,
            'gamesFinished' => $user->games()->finished()->count(),
            'turnsTaken' => (int) $user->games()->finished()->sum('turns'),
        ]);
    }
}
