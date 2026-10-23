<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaderboardController extends Controller
{
    /**
     * Player rankings built from every finished solo game by a verified
     * account, one row per player.
     */
    public function __invoke(Request $request): View
    {
        $grids = config('game.difficulties');

        $data = $request->validate([
            'grid' => ['sometimes', Rule::in(array_keys($grids))],
        ]);

        $grid = $data['grid'] ?? config('game.default_difficulty');
        $minimumGames = config('game.rankings_min_games');

        $gridRankings = $this->rankedGames()
            ->where('difficulty', $grid)
            ->selectRaw('user_id, MIN(turns) as best_turns, MIN(seconds) as best_seconds, AVG(turns) as average_turns, COUNT(*) as games_played')
            ->groupBy('user_id')
            ->orderBy('best_turns')
            ->orderBy('average_turns')
            ->limit(25)
            ->with('user:id,name,avatar_url')
            ->get();

        // Turns per pair evens out grid sizes: 1.0 is a perfect game anywhere.
        $overallRankings = $this->rankedGames()
            ->where('pairs', '>', 0)
            ->selectRaw('user_id, COUNT(*) as games_played, SUM(pairs) as pairs_found, AVG(1.0 * turns / pairs) as turns_per_pair')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= ?', [$minimumGames])
            ->orderBy('turns_per_pair')
            ->orderByDesc('games_played')
            ->limit(25)
            ->with('user:id,name,avatar_url')
            ->get();

        return view('leaderboard', [
            'grids' => $grids,
            'grid' => $grid,
            'gridRankings' => $gridRankings,
            'overallRankings' => $overallRankings,
            'minimumGames' => $minimumGames,
        ]);
    }

    /**
     * @return Builder<Game>
     */
    private function rankedGames(): Builder
    {
        return Game::query()
            ->finished()
            ->ranked()
            ->where('players', 1)
            ->whereHas('user', fn (Builder $query) => $query->whereNotNull('email_verified_at'));
    }
}
