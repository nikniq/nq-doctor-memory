<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_and_json_endpoints_send_the_security_headers(): void
    {
        foreach (['/', '/scores?difficulty=4x4'] as $url) {
            $this->get($url)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        }
    }

    public function test_analytics_never_sees_reset_tokens_or_query_strings(): void
    {
        config(['services.google_analytics.measurement_id' => 'G-TEST']);

        $this->get(route('password.reset', ['token' => 'abc', 'email' => 'a@b.c']))->assertDontSee('googletagmanager');
        $this->get('/login')->assertDontSee('googletagmanager');
        $this->get('/')->assertSee('googletagmanager')->assertSee('page_location', false);
    }
}
