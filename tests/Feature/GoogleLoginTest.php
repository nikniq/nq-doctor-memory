<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fakeGoogle(array $attributes = []): void
    {
        Socialite::fake('google', GoogleUser::fake(array_merge([
            'id' => 'google-123',
            'name' => 'Meredith Grey',
            'email' => 'Meredith@Example.com',
            'avatar' => 'https://example.com/a.jpg',
            'email_verified' => true,
        ], $attributes)));
    }

    public function test_a_new_google_player_gets_a_verified_account_without_a_password(): void
    {
        $this->fakeGoogle();

        $this->get('/auth/google/callback')->assertRedirect(route('game'));

        $user = User::where('google_id', 'google-123')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('meredith@example.com', $user->email);
        $this->assertSame('Meredith Grey', $user->name);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse($user->hasPassword());
    }

    public function test_a_verified_account_with_the_same_email_is_linked_and_keeps_its_password(): void
    {
        $user = User::factory()->create(['email' => 'meredith@example.com']);
        $this->fakeGoogle();

        $this->get('/auth/google/callback');

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->google_id);
        $this->assertTrue($user->hasPassword());
        $this->assertSame(1, User::count());
    }

    public function test_linking_an_unverified_account_clears_the_password_set_by_whoever_registered_it(): void
    {
        $squatter = User::factory()->unverified()->create(['email' => 'meredith@example.com']);
        $this->fakeGoogle();

        $this->get('/auth/google/callback');

        $squatter->refresh();
        $this->assertFalse($squatter->hasPassword());
        $this->assertTrue($squatter->hasVerifiedEmail());
    }

    public function test_an_unverified_google_email_is_refused(): void
    {
        User::factory()->create(['email' => 'meredith@example.com']);
        $this->fakeGoogle(['email_verified' => false]);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_google_routes_are_hidden_until_configured(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get('/login')->assertDontSee('Continue with Google');
    }

    public function test_the_login_page_offers_google_when_configured(): void
    {
        $this->get('/login')->assertSee('Continue with Google');
    }
}
