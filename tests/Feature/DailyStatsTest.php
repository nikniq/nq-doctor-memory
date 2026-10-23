<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Support\Daily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_count_only_todays_ranked_solo_daily_games_on_that_grid(): void
    {
        $this->freezeTime();
        foreach ([12, 12, 14, 30] as $turns) {
            Game::factory()->daily()->finished(turns: $turns)->create();
        }

        // None of these count.
        Game::factory()->daily()->twoPlayer()->finished(turns: 10)->create();
        Game::factory()->daily()->create();
        Game::factory()->daily()->finished(turns: 10)->create(['unranked_reason' => Game::PRACTICE]);
        Game::factory()->finished(turns: 10)->create(['played_on' => now()->subDay()->toDateString()]);
        Game::factory()->daily()->grid('6x6', 18)->finished(turns: 30)->create();
        Game::factory()->finished(turns: 10)->create();

        $this->getJson('/daily/stats?difficulty=4x4')
            ->assertOk()
            ->assertJson([
                'date' => now()->toDateString(),
                'players' => 4,
                'median_turns' => 13,
            ])
            ->assertJsonPath('buckets.0', ['turns' => 12, 'count' => 2])
            ->assertJsonPath('buckets.2', ['turns' => 14, 'count' => 1])
            ->assertJsonPath('buckets.12', ['turns' => 24, 'count' => 0])
            ->assertJsonPath('buckets.13', ['turns' => '25+', 'count' => 1]);
    }

    public function test_an_unknown_grid_is_rejected(): void
    {
        $this->getJson('/daily/stats?difficulty=9x9')->assertUnprocessable()->assertJsonValidationErrors('difficulty');
    }

    public function test_the_finish_response_says_how_many_players_this_game_beat(): void
    {
        $this->freezeTime();
        Game::factory()->daily()->finished(turns: 14, seconds: 50)->create(); // worse: more turns
        Game::factory()->daily()->finished(turns: 12, seconds: 70)->create(); // worse: same turns, slower
        Game::factory()->daily()->finished(turns: 12, seconds: 30)->create(); // better: same turns, faster
        Game::factory()->daily()->finished(turns: 10, seconds: 90)->create(); // better: fewer turns

        $game = Game::factory()->daily()->create(['started_at' => now()->subMinutes(2), 'attempt' => 1]);

        $this->postJson("/games/{$game->id}/finish", ['turns' => 12, 'seconds' => 60])
            ->assertOk()
            ->assertJsonPath('daily.players', 5)
            ->assertJsonPath('daily.beaten', 2)
            ->assertJsonPath('daily.number', Daily::number(now()->toDateString()));
    }

    public function test_free_play_and_practice_get_no_daily_standing(): void
    {
        $free = Game::factory()->create();
        $this->postJson("/games/{$free->id}/finish", ['turns' => 12, 'seconds' => 30])->assertJsonPath('daily', null);
    }

    public function test_daily_numbers_count_from_the_epoch(): void
    {
        config(['game.daily_epoch' => '2026-10-01']);

        $this->assertSame(1, Daily::number('2026-10-01'));
        $this->assertSame(23, Daily::number('2026-10-23'));
    }
}
