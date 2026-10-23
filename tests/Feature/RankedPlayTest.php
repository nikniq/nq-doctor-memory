<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Score;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankedPlayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Start and finish a game through the API, returning the finish response data.
     *
     * @param  array<string, mixed>  $start
     * @return array<string, mixed>
     */
    private function play(array $start, int $turns, int $seconds = 60): array
    {
        $this->travel(-$seconds - 5)->seconds();
        $token = $this->postJson('/games', $start)->assertCreated()->json('token');
        $this->travelBack();

        return $this->postJson("/games/{$token}/finish", ['turns' => $turns, 'seconds' => $seconds])
            ->assertOk()
            ->json() + ['token' => $token];
    }

    public function test_a_second_daily_game_on_the_same_board_is_practice(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->actingAs($user);

        $first = $this->play(['difficulty' => '4x4', 'daily' => true], 14);
        $second = $this->play(['difficulty' => '4x4', 'daily' => true], 10);

        $this->assertTrue($first['ranked']);
        $this->assertFalse($second['ranked']);
        $this->assertSame('practice', $second['reason']);

        $this->postJson('/scores', ['game' => $second['token']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game' => "Replays of today's board are practice and don't go on the top scores."]);

        // The practice game's 10 turns never reach the rankings.
        $this->get('/leaderboard?grid=4x4')
            ->assertViewHas('gridRankings', fn ($rows) => (int) $rows->first()->best_turns === 14);

        // Other grids and free play are unaffected.
        $this->assertTrue($this->play(['difficulty' => '4x3', 'daily' => true], 9)['ranked']);
        $this->assertTrue($this->play(['difficulty' => '4x4'], 12)['ranked']);
    }

    public function test_a_guest_replaying_the_daily_board_in_one_session_is_practicing(): void
    {
        $this->freezeTime();

        $this->assertTrue($this->play(['difficulty' => '4x3', 'daily' => true], 9)['ranked']);
        $this->assertSame('practice', $this->play(['difficulty' => '4x3', 'daily' => true], 9)['reason']);
    }

    public function test_the_page_says_which_daily_boards_were_already_played(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->daily()->grid('6x6', 18)->create();

        $this->actingAs($user)->get('/')->assertSee('data-daily-played=\'["6x6"]\'', false);
    }

    public function test_results_too_lucky_for_perfect_memory_are_held(): void
    {
        $this->freezeTime();

        $this->assertSame('implausible', $this->play(['difficulty' => '4x4'], 8)['reason']);
        $this->assertTrue($this->play(['difficulty' => '4x4'], 9)['ranked']);
        $this->assertSame('implausible', $this->play(['difficulty' => '6x6'], 18, 120)['reason']);
    }

    public function test_results_faster_than_the_misses_allow_are_held(): void
    {
        $this->freezeTime();

        // 12 misses lock the board for at least 10.8 seconds.
        $held = $this->play(['difficulty' => '4x4'], 20, 1);

        $this->assertSame('implausible', $held['reason']);
        $this->postJson('/scores', ['game' => $held['token'], 'player_name' => 'Bot'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['game' => 'This result is being held for review.']);
    }

    public function test_a_verified_player_keeps_one_row_per_board(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->actingAs($user);

        $first = $this->play(['difficulty' => '4x4'], 12);
        $this->postJson('/scores', ['game' => $first['token']])->assertCreated()->assertJson(['improved' => true]);

        $worse = $this->play(['difficulty' => '4x4'], 15);
        $this->postJson('/scores', ['game' => $worse['token']])
            ->assertOk()
            ->assertJson(['improved' => false, 'rank' => 1]);
        $this->assertSame(1, Score::count());
        $this->assertSame(12, Score::sole()->moves);

        $better = $this->play(['difficulty' => '4x4'], 10);
        $this->postJson('/scores', ['game' => $better['token']])->assertCreated()->assertJson(['improved' => true]);
        $this->assertSame(1, Score::count());
        $this->assertSame(10, Score::sole()->moves);
    }

    public function test_a_moderator_can_rank_a_held_game(): void
    {
        $game = Game::factory()->finished(turns: 8)->create(['unranked_reason' => Game::IMPLAUSIBLE]);

        $this->artisan('games:rank', ['game' => $game->id])->assertSuccessful();

        $this->assertTrue($game->fresh()->isRanked());
    }

    public function test_a_moderator_can_remove_a_score(): void
    {
        $game = Game::factory()->finished()->create();
        $score = Score::create(['player_name' => 'Spam', 'difficulty' => '4x4', 'moves' => 9, 'seconds' => 9]);
        $game->update(['score_id' => $score->id]);

        $this->artisan('scores:remove', ['score' => $score->id])->assertSuccessful();

        $this->assertModelMissing($score);
        $this->assertNull($game->fresh()->score_id);
    }
}
