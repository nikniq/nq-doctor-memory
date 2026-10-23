<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function test_only_a_verified_account_owns_the_games_it_starts(): void
    {
        $this->postJson('/games', ['difficulty' => '4x4'])->assertCreated()->assertJson(['account' => null]);

        $unverified = User::factory()->unverified()->create();
        $token = $this->actingAs($unverified)->postJson('/games', ['difficulty' => '4x4'])
            ->assertCreated()
            ->assertJson(['account' => null])
            ->json('token');
        $this->assertNull(Game::findOrFail($token)->user_id);

        $verified = User::factory()->create(['name' => 'House']);
        $this->actingAs($verified)->postJson('/games', ['difficulty' => '4x4'])
            ->assertCreated()
            ->assertJson(['account' => 'House']);
    }

    public function test_a_daily_game_must_be_for_todays_board(): void
    {
        $this->travelTo('2026-10-23 00:00:20');

        $this->postJson('/games', ['difficulty' => '4x4', 'daily' => true, 'date' => '2026-10-22'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'A new daily challenge has started. Reload to play it.']);

        $today = $this->postJson('/games', ['difficulty' => '4x4', 'daily' => true, 'date' => '2026-10-23'])
            ->assertCreated()
            ->json('token');
        $this->assertSame('2026-10-23', Game::findOrFail($today)->played_on->toDateString());

        $this->postJson('/games', ['difficulty' => '4x4', 'daily' => true])->assertCreated();
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

    public function test_impossible_turns_are_rejected(): void
    {
        $game = Game::factory()->create();

        $this->postJson("/games/{$game->id}/finish", ['turns' => 7, 'seconds' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['turns' => 'Turns must be at least 8.']);

        $this->assertFalse($game->fresh()->isFinished());
    }

    public function test_a_time_longer_than_the_server_clock_is_clamped_to_it(): void
    {
        $this->freezeTime();
        $game = Game::factory()->create(['started_at' => now()->subSeconds(20)]);

        $this->postJson("/games/{$game->id}/finish", ['turns' => 9, 'seconds' => 40])
            ->assertOk()
            ->assertJson(['seconds' => 23]);
    }

    public function test_a_game_saved_before_pairs_were_recorded_still_needs_one_turn_per_pair(): void
    {
        $id = (string) Str::uuid();
        DB::table('games')->insert([
            'id' => $id,
            'difficulty' => '4x4',
            'pairs' => 0,
            'players' => 1,
            'started_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/games/{$id}/finish", ['turns' => 1, 'seconds' => 30])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['turns' => 'Turns must be at least 8.']);
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
