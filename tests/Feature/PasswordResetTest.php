<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_is_sent_without_revealing_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_the_reset_link_sets_a_new_password_and_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->google()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('newpass123', $user->password));
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-a-token',
            'email' => $user->email,
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertSessionHasErrors(['email' => 'This password reset token is invalid.']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_reset_signs_out_every_earlier_session(): void
    {
        config(['session.driver' => 'database']);
        Notification::fake();

        $register = $this->post('/register', [
            'name' => 'Squatter',
            'email' => 'victim@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);
        $squatterSession = $register->getCookie(config('session.cookie'))->getValue();
        $this->freshBrowser();

        $user = User::sole();
        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'victim@example.com',
            'password' => 'owner-pass1',
            'password_confirmation' => 'owner-pass1',
        ])->assertRedirect(route('login'));
        $this->freshBrowser();

        $this->withCookie(config('session.cookie'), $squatterSession)
            ->get('/account')
            ->assertRedirect(route('login'));
    }

    public function test_the_reset_link_is_sent_whatever_the_capitalisation(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'house@example.com']);

        $this->post('/forgot-password', ['email' => 'House@Example.com']);

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
