<?php

namespace App\Http\Controllers;

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
        ]);
    }
}
