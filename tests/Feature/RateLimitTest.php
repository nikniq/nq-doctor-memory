<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function spamResetRequests(string $email): void
    {
        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            foreach (range(1, 5) as $attempt) {
                $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/forgot-password', ['email' => $email]);
            }
        }
    }

    public function test_spamming_reset_requests_from_elsewhere_does_not_lock_the_owner_out(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'victim@example.com']);

        $this->spamResetRequests('victim@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
            ->post('/forgot-password', ['email' => 'victim@example.com'])
            ->assertRedirect();
    }

    public function test_spamming_an_address_does_not_block_its_owner_resending_verification(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create(['email' => 'victim@example.com']);

        $this->spamResetRequests('victim@example.com');

        $this->actingAs($owner)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])
            ->post('/email/verification-notification')
            ->assertRedirect();
    }
}
