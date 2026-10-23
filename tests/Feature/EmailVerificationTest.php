<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrl(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->email),
        ]);
    }

    public function test_the_link_verifies_the_signed_in_account(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);

        $this->actingAs($user)->get($this->verificationUrl($user))
            ->assertRedirect(route('game'))
            ->assertSessionHas('status');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_the_link_works_signed_out_without_signing_anyone_in(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user))->assertRedirect(route('login'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertGuest();
    }

    public function test_tampered_or_unsigned_links_are_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->verificationUrl($user, sha1('someone@else.com')))->assertForbidden();
        $this->get("/email/verify/{$user->id}/".sha1($user->email))->assertRedirect(route('login'));

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_player_can_ask_for_a_new_link(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post('/email/verification-notification')->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_account_page_requires_a_verified_email(): void
    {
        $this->get('/account')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->unverified()->create())
            ->get('/account')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_playing_games_does_not_use_up_the_verification_link_limit(): void
    {
        $user = User::factory()->unverified()->create();

        foreach (range(1, 3) as $round) {
            $token = $this->actingAs($user)->postJson('/games', ['difficulty' => '4x3'])->json('token');
            $this->actingAs($user)->postJson("/games/{$token}/finish", ['turns' => 10, 'seconds' => 1])->assertOk();
        }

        $this->actingAs($user)->get($this->verificationUrl($user))->assertRedirect(route('game'));
    }

    public function test_an_expired_link_explains_itself_instead_of_showing_an_error_page(): void
    {
        $user = User::factory()->unverified()->create();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($expired)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'That link has expired or is invalid. Log in and we will send a new one.');

        $this->actingAs($user)->get($expired)->assertRedirect(route('verification.notice'));
    }
}
