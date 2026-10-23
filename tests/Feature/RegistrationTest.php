<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_creates_an_unverified_account_and_emails_a_verification_link(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Dr Grey',
            'email' => 'grey@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $user = User::where('email', 'grey@example.com')->sole();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_validates_its_fields(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [])->assertSessionHasErrors(['name', 'email', 'password']);

        $this->post('/register', [
            'name' => str_repeat('x', 25),
            'email' => 'taken@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'different1',
        ])->assertSessionHasErrors([
            'name' => 'The name field must not be greater than 24 characters.',
            'email' => 'The email has already been taken.',
            'password' => 'The password field confirmation does not match.',
        ]);

        $this->assertGuest();
    }

    public function test_signed_in_players_are_sent_back_to_the_game(): void
    {
        $this->actingAs(User::factory()->create())->get('/register')->assertRedirect('/');
    }

    public function test_the_email_is_stored_lowercased(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Grey',
            'email' => 'Meredith@Example.COM',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'meredith@example.com']);
    }
}
