<?php

namespace Tests\Feature;

use App\Actions\DeleteAccount;
use App\Models\Game;
use App\Models\Score;
use App\Models\User;
use App\Notifications\ConfirmAccountDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_export_holds_only_the_players_own_data_and_no_secrets(): void
    {
        $user = User::factory()->create(['name' => 'House']);
        Game::factory()->for($user)->finished(turns: 12)->create();
        Score::create(['user_id' => $user->id, 'player_name' => 'House', 'difficulty' => '4x4', 'moves' => 12, 'seconds' => 40]);
        Game::factory()->for(User::factory())->finished(turns: 30)->create();

        $response = $this->actingAs($user)->get('/account/export')->assertOk();
        $json = $response->streamedContent();
        $data = json_decode($json, true);

        $this->assertSame('House', $data['profile']['name']);
        $this->assertCount(1, $data['games']);
        $this->assertSame(12, $data['games'][0]['turns']);
        $this->assertCount(1, $data['scores']);
        foreach (['password', 'remember_token', 'google_id'] as $secret) {
            $this->assertStringNotContainsString("\"{$secret}\"", $json);
        }
        $this->assertStringContainsString('doctor-memory-data-', $response->headers->get('content-disposition'));
    }

    public function test_deleting_with_the_password_removes_every_trace(): void
    {
        config(['session.driver' => 'database', 'game.rankings_min_games' => 1]);
        $user = User::factory()->create(['name' => 'Gone Soon']);
        Game::factory()->for($user)->finished(turns: 12)->create();
        Score::create(['user_id' => $user->id, 'player_name' => 'Gone Soon', 'difficulty' => '4x4', 'moves' => 12, 'seconds' => 40]);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)
            ->delete('/account', ['password' => 'password'])
            ->assertRedirect(route('game'))
            ->assertSessionHas('status', 'Your account and games were deleted.');

        $this->assertGuest();
        $this->assertModelMissing($user);
        $this->assertSame(0, Game::count());
        $this->assertSame(0, Score::count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->getJson('/scores?difficulty=4x4')->assertJsonCount(0, 'data');
        $this->get('/leaderboard')->assertDontSee('Gone Soon');
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/account', ['password' => 'not-it'])
            ->assertSessionHasErrors(['password' => 'The password is incorrect.']);

        $this->assertModelExists($user);
    }

    public function test_a_google_only_account_confirms_deletion_by_email(): void
    {
        Notification::fake();
        $user = User::factory()->google()->create();

        $this->actingAs($user)->post('/account/delete-link')->assertSessionHas('status');
        Notification::assertSentTo($user, ConfirmAccountDeletion::class);

        $signed = URL::temporarySignedRoute('account.destroy.confirm', now()->addMinutes(30), ['user' => $user->id]);

        $this->actingAs($user)->get($signed)->assertOk()->assertSee('Yes, delete my account');
        $this->assertModelExists($user); // looking is not deleting

        $this->actingAs($user)->post($signed.'x')->assertForbidden();
        $this->assertModelExists($user);

        $this->actingAs($user)->post($signed)->assertRedirect(route('game'));
        $this->assertModelMissing($user);
    }

    public function test_a_signed_link_only_works_for_its_own_account(): void
    {
        $owner = User::factory()->google()->create();
        $signed = URL::temporarySignedRoute('account.destroy.confirm', now()->addMinutes(30), ['user' => $owner->id]);

        $this->actingAs(User::factory()->create())->post($signed)->assertForbidden();

        $this->assertModelExists($owner);
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get('/account/export')->assertRedirect(route('login'));
        $this->delete('/account')->assertRedirect(route('login'));
    }

    public function test_every_table_with_a_user_id_is_cleared_on_deletion(): void
    {
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'user_id'))
            ->sort()
            ->values()
            ->all();

        $listed = DeleteAccount::TABLES_WITH_USER_ID;
        sort($listed);

        $this->assertSame($listed, $tables, 'A table with user_id is missing from DeleteAccount::TABLES_WITH_USER_ID.');
    }

    public function test_the_privacy_page_is_public(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('What others can see');
    }
}
