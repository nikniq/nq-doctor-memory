<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Score;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_posts_a_finished_game_under_a_chosen_name(): void
    {
        $game = Game::factory()->finished(turns: 14, seconds: 58)->create();

        $response = $this->postJson('/scores', ['game' => $game->id, 'player_name' => '  Dr Who ']);

        $response->assertCreated()
            ->assertJsonPath('data.player_name', 'Dr Who')
            ->assertJsonPath('data.moves', 14)
            ->assertJsonPath('rank', 1);

        $this->assertDatabaseHas('scores', ['player_name' => 'Dr Who', 'user_id' => null, 'moves' => 14, 'seconds' => 58]);
        $this->assertSame($response->json('data.id'), $game->fresh()->score_id);
    }

    public function test_a_verified_player_posts_under_their_account_name(): void
    {
        $user = User::factory()->create(['name' => 'House']);
        $game = Game::factory()->for($user)->finished()->create();

        $this->actingAs($user)
            ->postJson('/scores', ['game' => $game->id, 'player_name' => 'Someone Else'])
            ->assertCreated()
            ->assertJsonPath('data.player_name', 'House');

        $this->assertDatabaseHas('scores', ['user_id' => $user->id, 'player_name' => 'House']);
    }

    public function test_a_guest_must_choose_a_name(): void
    {
        $game = Game::factory()->finished()->create();

        $this->postJson('/scores', ['game' => $game->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['player_name']);
    }

    public function test_the_game_must_be_open_finished_solo_and_yours(): void
    {
        $unfinished = Game::factory()->create();
        $twoPlayer = Game::factory()->twoPlayer()->finished()->create();
        $someoneElses = Game::factory()->for(User::factory())->finished()->create();
        $scored = Game::factory()->finished()->create(['scored_at' => now()]);

        $cases = [
            [fake()->uuid(), 'Start a game before submitting a score.'],
            [$unfinished->id, 'Finish the game before submitting a score.'],
            [$twoPlayer->id, 'Two-player games are not ranked.'],
            [$someoneElses->id, 'Start a game before submitting a score.'],
            [$scored->id, 'This game has already been scored.'],
        ];

        foreach ($cases as [$token, $message]) {
            $this->postJson('/scores', ['game' => $token, 'player_name' => 'X'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['game' => $message]);
        }

        $this->assertDatabaseCount('scores', 0);
    }

    public function test_an_expired_daily_game_is_rejected(): void
    {
        $game = Game::factory()->finished()->create(['played_on' => now()->subDay()->toDateString()]);

        $this->postJson('/scores', ['game' => $game->id, 'player_name' => 'Late'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game' => 'That daily challenge has ended.']);
    }

    public function test_rank_counts_better_scores_on_the_same_grid(): void
    {
        Score::create(['player_name' => 'A', 'difficulty' => '4x4', 'moves' => 10, 'seconds' => 30]);
        Score::create(['player_name' => 'B', 'difficulty' => '4x4', 'moves' => 12, 'seconds' => 20]);
        Score::create(['player_name' => 'C', 'difficulty' => '6x4', 'moves' => 12, 'seconds' => 20]);

        $game = Game::factory()->finished(turns: 12, seconds: 25)->create();

        $this->postJson('/scores', ['game' => $game->id, 'player_name' => 'Me'])
            ->assertCreated()
            ->assertJsonPath('rank', 3);
    }

    public function test_leaderboard_is_ranked_scoped_to_a_grid_and_flags_registered_players(): void
    {
        $user = User::factory()->create();
        Score::create(['player_name' => 'Slow', 'difficulty' => '4x3', 'moves' => 9, 'seconds' => 90]);
        Score::create(['user_id' => $user->id, 'player_name' => 'Fast', 'difficulty' => '4x3', 'moves' => 9, 'seconds' => 40]);
        Score::create(['player_name' => 'Few', 'difficulty' => '4x3', 'moves' => 7, 'seconds' => 99]);
        Score::create(['player_name' => 'Other', 'difficulty' => '6x4', 'moves' => 1, 'seconds' => 1]);

        $this->getJson('/scores?difficulty=4x3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.player_name', 'Few')
            ->assertJsonPath('data.1.player_name', 'Fast')
            ->assertJsonPath('data.1.registered', true)
            ->assertJsonPath('data.2.registered', false);

        $this->getJson('/scores?difficulty=easy')->assertUnprocessable();
    }

    public function test_leaderboard_is_capped(): void
    {
        config(['game.leaderboard_size' => 2]);
        foreach (range(1, 3) as $i) {
            Score::create(['player_name' => "P$i", 'difficulty' => '4x3', 'moves' => 10 + $i, 'seconds' => 10]);
        }

        $this->getJson('/scores?difficulty=4x3')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_daily_scores_are_dated_and_kept_apart_from_free_play(): void
    {
        $this->travelTo('2026-10-16 12:00:00');

        Score::create(['player_name' => 'Free', 'difficulty' => '4x3', 'moves' => 6, 'seconds' => 10]);
        Score::create(['player_name' => 'Yesterday', 'difficulty' => '4x3', 'played_on' => '2026-10-15', 'moves' => 6, 'seconds' => 10]);

        $game = Game::factory()->grid('4x3', 6)->daily()->finished(turns: 9, seconds: 30)->create();

        $this->postJson('/scores', ['game' => $game->id, 'player_name' => 'Today'])
            ->assertCreated()
            ->assertJsonPath('data.played_on', '2026-10-16')
            ->assertJsonPath('rank', 1);

        $this->getJson('/scores?difficulty=4x3&daily=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.player_name', 'Today');

        $this->getJson('/scores?difficulty=4x3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.player_name', 'Free');
    }

    public function test_overlapping_submissions_score_a_game_only_once(): void
    {
        $game = Game::factory()->finished(turns: 10, seconds: 20)->create();
        $nested = false;

        // A second POST /scores arrives after the first passed its checks but
        // before it created the score row.
        Score::creating(function () use (&$nested, $game) {
            if ($nested) {
                return;
            }
            $nested = true;
            $this->postJson('/scores', ['game' => $game->id, 'player_name' => 'Twin'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['game' => 'This game has already been scored.']);
        });

        $this->postJson('/scores', ['game' => $game->id, 'player_name' => 'Twin'])->assertCreated();

        $this->assertSame(1, Score::count());
    }

    public function test_scoring_several_games_in_a_minute_is_not_throttled_by_starting_them(): void
    {
        foreach (range(1, 4) as $round) {
            $token = $this->postJson('/games', ['difficulty' => '4x3'])->assertCreated()->json('token');
            $this->postJson("/games/{$token}/finish", ['turns' => 10, 'seconds' => 1])->assertOk();
            $this->postJson('/scores', ['game' => $token, 'player_name' => 'Busy'])->assertCreated();
        }
    }
}
