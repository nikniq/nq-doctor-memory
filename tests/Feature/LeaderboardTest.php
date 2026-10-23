<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_rankings_show_one_row_per_verified_player_by_best_turns(): void
    {
        $house = User::factory()->create(['name' => 'House']);
        $grey = User::factory()->create(['name' => 'Grey']);
        $unverified = User::factory()->unverified()->create(['name' => 'Ghost']);

        Game::factory()->for($house)->finished(turns: 12)->create();
        Game::factory()->for($house)->finished(turns: 9)->create();
        Game::factory()->for($grey)->finished(turns: 10)->create();
        Game::factory()->for($unverified)->finished(turns: 8)->create();
        Game::factory()->for($grey)->twoPlayer()->finished(turns: 8)->create();
        Game::factory()->finished(turns: 8)->create(); // guest
        Game::factory()->for($grey)->grid('6x6', 18)->finished(turns: 18)->create();

        $response = $this->get('/leaderboard?grid=4x4')->assertOk();

        $response->assertViewHas('gridRankings', function ($rows) use ($house, $grey) {
            return $rows->pluck('user_id')->all() === [$house->id, $grey->id]
                && (int) $rows[0]->best_turns === 9
                && (int) $rows[0]->games_played === 2;
        });
        $response->assertSeeInOrder(['House', 'Grey'])->assertDontSee('Ghost');
    }

    public function test_overall_rankings_need_the_minimum_games_and_compare_turns_per_pair(): void
    {
        config(['game.rankings_min_games' => 2]);
        $sharp = User::factory()->create(['name' => 'Sharp']);
        $steady = User::factory()->create(['name' => 'Steady']);
        $newcomer = User::factory()->create(['name' => 'Newcomer']);

        // Sharp: 8/8 and 18/18 → 1.00 per pair. Steady: 16/8 twice → 2.00.
        Game::factory()->for($sharp)->finished(turns: 8)->create();
        Game::factory()->for($sharp)->grid('6x6', 18)->finished(turns: 18)->create();
        Game::factory()->for($steady)->count(2)->finished(turns: 16)->create();
        Game::factory()->for($newcomer)->finished(turns: 8)->create();

        $this->get('/leaderboard')
            ->assertOk()
            ->assertViewHas('overallRankings', fn ($rows) => $rows->pluck('user_id')->all() === [$sharp->id, $steady->id]);
    }

    public function test_an_unknown_grid_is_rejected(): void
    {
        $this->get('/leaderboard?grid=9x9')->assertSessionHasErrors('grid');
    }
}
