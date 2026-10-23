<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_game_records_the_grid_mode_players_and_account(): void
    {
        $user = User::factory()->create();

        $token = $this->actingAs($user)
            ->postJson('/games', ['difficulty' => '6x5', 'daily' => true, 'players' => 2])
            ->assertCreated()
            ->json('token');

        $game = Game::findOrFail($token);
        $this->assertSame($user->id, $game->user_id);
        $this->assertSame(15, $game->pairs);
        $this->assertSame(2, $game->players);
        $this->assertTrue($game->isDaily());
    }

    public function test_starting_a_game_validates_the_grid_and_player_count(): void
    {
        $this->postJson('/games', ['difficulty' => '9x9', 'players' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['difficulty', 'players']);
    }

    public function test_finishing_a_game_captures_its_turns_and_time(): void
    {
        $game = Game::factory()->create();

        $this->postJson("/games/{$game->id}/finish", ['turns' => 14, 'seconds' => 55])
            ->assertOk()
            ->assertJson(['turns' => 14, 'seconds' => 55, 'scorable' => true]);

        $game->refresh();
        $this->assertSame(14, $game->turns);
        $this->assertSame(55, $game->seconds);
        $this->assertTrue($game->isFinished());
    }

    public function test_two_player_games_are_captured_but_not_scorable(): void
    {
        $game = Game::factory()->twoPlayer()->create();

        $this->postJson("/games/{$game->id}/finish", ['turns' => 20, 'seconds' => 30])
            ->assertOk()
            ->assertJson(['scorable' => false]);
    }

    public function test_impossible_turns_and_times_are_rejected(): void
    {
        $game = Game::factory()->create(['started_at' => now()->subSeconds(20)]);

        $this->postJson("/games/{$game->id}/finish", ['turns' => 7, 'seconds' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['turns' => 'Turns must be at least 8.']);

        $this->postJson("/games/{$game->id}/finish", ['turns' => 9, 'seconds' => 40])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seconds' => 'That time does not match the server clock.']);

        $this->assertFalse($game->fresh()->isFinished());
    }

    public function test_a_game_can_only_be_finished_once(): void
    {
        $game = Game::factory()->finished()->create();

        $this->postJson("/games/{$game->id}/finish", ['turns' => 9, 'seconds' => 20])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game' => 'This game has already finished.']);
    }

    public function test_another_account_cannot_finish_your_game(): void
    {
        $game = Game::factory()->for(User::factory())->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/games/{$game->id}/finish", ['turns' => 9, 'seconds' => 20])
            ->assertNotFound();

        $this->postJson("/games/{$game->id}/finish", ['turns' => 9, 'seconds' => 20])->assertNotFound();
    }

    public function test_only_unfinished_stale_games_are_pruned(): void
    {
        $staleOpen = Game::factory()->create(['created_at' => now()->subDays(2)]);
        $staleFinished = Game::factory()->finished()->create(['created_at' => now()->subDays(2)]);
        $freshOpen = Game::factory()->create();

        $this->artisan('model:prune', ['--model' => [Game::class]])->assertSuccessful();

        $this->assertModelMissing($staleOpen);
        $this->assertModelExists($staleFinished);
        $this->assertModelExists($freshOpen);
    }
}
