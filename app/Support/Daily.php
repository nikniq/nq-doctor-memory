<?php

namespace App\Support;

use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The daily challenge: its number, and the games that count on its board.
 */
class Daily
{
    /**
     * Daily challenge number: 1 on config('game.daily_epoch'), counting up a day at a time.
     */
    public static function number(string $date): int
    {
        $epoch = Carbon::parse(config('game.daily_epoch'))->startOfDay();

        return (int) $epoch->diffInDays(Carbon::parse($date)->startOfDay()) + 1;
    }

    /**
     * Ranked, finished solo games on one daily board.
     *
     * @return Builder<Game>
     */
    public static function rankedGames(string $date, string $grid): Builder
    {
        return Game::query()
            ->whereDate('played_on', $date)
            ->where('difficulty', $grid)
            ->where('players', 1)
            ->finished()
            ->ranked();
    }
}
