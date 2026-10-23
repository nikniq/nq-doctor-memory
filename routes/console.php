<?php

use App\Models\Game;
use App\Models\Score;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Game-session rows are only needed until they are scored.
Schedule::command('model:prune', ['--model' => [Game::class]])->daily();

// Moderation of held results and top scores.
Artisan::command('games:held', function () {
    $games = Game::where('unranked_reason', Game::IMPLAUSIBLE)->latest('finished_at')->limit(50)->get();
    if ($games->isEmpty()) {
        $this->info('No held games.');

        return;
    }
    $this->table(
        ['Game', 'Player', 'Grid', 'Daily', 'Turns', 'Seconds', 'Finished'],
        $games->map(fn ($game) => [$game->id, $game->user?->name ?? 'guest', $game->difficulty, $game->played_on?->toDateString() ?? '-', $game->turns, $game->seconds, $game->finished_at])->all(),
    );
})->purpose('List finished games held for review as implausible');

Artisan::command('games:rank {game}', function (string $game) {
    $updated = Game::whereKey($game)->whereNotNull('unranked_reason')->update(['unranked_reason' => null]);
    $updated ? $this->info("Game {$game} is ranked.") : $this->error('No held or practice game with that id.');
})->purpose('Clear the hold on a game so it counts towards the rankings');

Artisan::command('scores:remove {score}', function (int $score) {
    $removed = DB::transaction(function () use ($score) {
        Game::where('score_id', $score)->update(['score_id' => null]);

        return Score::whereKey($score)->delete();
    });
    $removed ? $this->info("Score {$score} removed.") : $this->error('No score with that id.');
})->purpose('Remove a top-scores row');
