<?php

namespace Tests\Feature;

use Tests\TestCase;

class GameTest extends TestCase
{
    public function test_the_game_page_renders(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee(config('game.title'))
            ->assertSee('Turns')
            ->assertSee('Stethoscope')
            ->assertSee('New Game')
            ->assertSee('Grid')
            ->assertSee('6 × 6')
            ->assertSee('Daily challenge')
            ->assertSee('data-today="'.now()->toDateString().'"', false)
            ->assertSee(route('scores.store'), false)
            ->assertSee(route('games.store'), false)
            ->assertSee('2 players');
    }

    public function test_analytics_tag_is_only_rendered_when_configured(): void
    {
        config(['services.google_analytics.measurement_id' => null]);
        $this->get('/')->assertDontSee('googletagmanager');

        config(['services.google_analytics.measurement_id' => 'G-TEST123']);
        $this->get('/')->assertSee('googletagmanager.com/gtag/js?id=G-TEST123', false);
    }

    public function test_a_missing_page_offers_a_way_back(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('href="'.url('/').'"', false);
    }

    public function test_one_live_region_announces_the_game(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('/id="announcer"[^>]*aria-live="polite"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="fact"[^>]*role="status"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="playersBar"[^>]*role="status"/', $html);
    }
}
