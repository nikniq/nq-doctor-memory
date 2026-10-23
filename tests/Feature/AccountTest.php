<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_page_summarises_only_the_players_own_finished_games(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->finished(turns: 10, seconds: 40)->create();
        Game::factory()->for($user)->finished(turns: 14, seconds: 30)->create();
        Game::factory()->for($user)->twoPlayer()->finished(turns: 20)->create();
        Game::factory()->for($user)->create(); // unfinished
        Game::factory()->for(User::factory())->finished(turns: 8)->create();

        $response = $this->actingAs($user)->get('/account')->assertOk();

        $response->assertViewHas('gamesFinished', 3);
        $response->assertViewHas('turnsTaken', 44);
        $response->assertViewHas('gridStats', function ($stats) {
            $grid = $stats->get('4x4');

            return $stats->count() === 1
                && (int) $grid->games_played === 2
                && (int) $grid->best_turns === 10
                && (int) $grid->best_seconds === 30;
        });
        $response->assertViewHas('recentGames', fn ($games) => $games->count() === 3);
    }
}
