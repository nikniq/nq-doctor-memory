<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Score;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function establishedPlayer(array $attributes = []): User
    {
        return User::factory()->create(['created_at' => now()->subDays(30)] + $attributes);
    }

    public function test_settings_need_a_verified_account(): void
    {
        $this->get('/account/settings')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->get('/account/settings')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_player_can_rename_themselves_and_old_scores_follow(): void
    {
        $user = $this->establishedPlayer(['name' => 'House']);
        Score::create(['user_id' => $user->id, 'player_name' => 'House', 'difficulty' => '4x4', 'moves' => 12, 'seconds' => 30]);

        $this->actingAs($user)->patch('/account/name', ['name' => '  Dr House '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'You now appear as Dr House.');

        $this->assertSame('Dr House', $user->fresh()->name);
        $this->getJson('/scores?difficulty=4x4')->assertJsonPath('data.0.player_name', 'Dr House');
    }

    public function test_names_are_limited_in_length_and_characters(): void
    {
        $user = $this->establishedPlayer();

        $this->actingAs($user)->patch('/account/name', ['name' => str_repeat('x', 25)])
            ->assertSessionHasErrors(['name' => 'The name field must not be greater than 24 characters.']);
        $this->actingAs($user)->patch('/account/name', ['name' => "Evil\u{202E}eman"])
            ->assertSessionHasErrors(['name' => 'The name contains characters that cannot be shown.']);
    }

    public function test_a_name_can_change_once_a_week(): void
    {
        $this->freezeTime();
        $user = $this->establishedPlayer(['name' => 'One']);

        $this->actingAs($user)->patch('/account/name', ['name' => 'Two'])->assertSessionHasNoErrors();
        $this->actingAs($user)->patch('/account/name', ['name' => 'Three'])->assertSessionHasErrors('name');
        $this->assertSame('Two', $user->fresh()->name);

        $this->travel(7)->days();
        $this->actingAs($user)->patch('/account/name', ['name' => 'Three'])->assertSessionHasNoErrors();
    }

    public function test_new_accounts_can_fix_their_name_without_waiting(): void
    {
        $user = User::factory()->create(['name' => 'Typo']);

        $this->actingAs($user)->patch('/account/name', ['name' => 'Fixed'])->assertSessionHasNoErrors();
        $this->actingAs($user)->patch('/account/name', ['name' => 'Final'])->assertSessionHasNoErrors();

        $this->assertSame('Final', $user->fresh()->name);
    }

    public function test_a_hidden_player_is_left_out_of_both_rankings(): void
    {
        config(['game.rankings_min_games' => 1]);
        $user = $this->establishedPlayer(['name' => 'Private Pat']);
        Game::factory()->for($user)->finished(turns: 12)->create();

        $this->get('/leaderboard')->assertSee('Private Pat');

        $this->actingAs($user)->patch('/account/privacy', ['show_in_rankings' => '0', 'show_avatar' => '0'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->get('/account')->assertSee('You are hidden from the public rankings.');
        $this->freshBrowser();
        $this->get('/leaderboard')->assertDontSee('Private Pat');
    }

    public function test_photos_appear_in_the_rankings_only_when_chosen(): void
    {
        $user = $this->establishedPlayer(['avatar_url' => 'https://example.com/photo.jpg']);
        Game::factory()->for($user)->finished(turns: 12)->create();

        $this->get('/leaderboard')->assertDontSee('https://example.com/photo.jpg');

        $this->actingAs($user)->patch('/account/privacy', ['show_in_rankings' => '1', 'show_avatar' => '1']);

        $this->get('/leaderboard')->assertSee('https://example.com/photo.jpg');
    }
}
