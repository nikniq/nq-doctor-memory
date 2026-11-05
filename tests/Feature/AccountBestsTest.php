<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountBestsTest extends TestCase
{
    use RefreshDatabase;

    private function bests(): array
    {
        preg_match("/data-account-bests='([^']*)'/", $this->get('/')->getContent(), $match);

        return json_decode(html_entity_decode($match[1]), true);
    }

    public function test_a_verified_player_gets_their_best_ranked_solo_game_per_board(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        Game::factory()->for($user)->finished(turns: 14, seconds: 40)->create();
        Game::factory()->for($user)->finished(turns: 12, seconds: 50)->create();
        Game::factory()->for($user)->finished(turns: 12, seconds: 45)->create();
        Game::factory()->for($user)->finished(turns: 9)->create(['unranked_reason' => Game::IMPLAUSIBLE]);
        Game::factory()->for($user)->twoPlayer()->finished(turns: 8)->create();
        Game::factory()->for($user)->daily()->finished(turns: 13, seconds: 20)->create();
        Game::factory()->for($user)->finished(turns: 10)->create(['played_on' => now()->subDay()->toDateString()]);

        $this->actingAs($user);
        $date = now()->toDateString();

        $this->assertSame([
            'best:4x4' => ['moves' => 12, 'seconds' => 45],
            "best:daily:{$date}:4x4" => ['moves' => 13, 'seconds' => 20],
        ], $this->bests());
    }

    public function test_guests_and_unverified_players_get_none(): void
    {
        $this->assertSame([], $this->bests());

        $this->actingAs(User::factory()->unverified()->create());
        $this->assertSame([], $this->bests());
    }
}
