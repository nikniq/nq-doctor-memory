<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
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
            ->assertSee('2 players')
            ->assertSee('id="settingsToggle"', false);
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function grids(): array
    {
        return collect((require __DIR__.'/../../config/game.php')['difficulties'])
            ->keys()
            ->mapWithKeys(fn (string $grid) => [$grid => [$grid]])
            ->all();
    }

    #[DataProvider('grids')]
    public function test_each_grid_has_sensible_star_cuts_on_the_page(string $grid): void
    {
        $settings = config("game.difficulties.{$grid}");

        $this->assertGreaterThanOrEqual($settings['pairs'], $settings['stars'][3]);
        $this->assertLessThan($settings['stars'][2], $settings['stars'][3]);

        $html = $this->get('/')->getContent();
        preg_match("/data-difficulties='([^']*)'/", $html, $match);
        $this->assertSame($settings['stars'][3], json_decode(html_entity_decode($match[1]), true)[$grid]['stars'][3]);
    }

    public function test_the_game_page_has_link_preview_metadata(): void
    {
        $html = $this->get('/?utm=x')->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Doctor Memory Game">', $html);
        $this->assertMatchesRegularExpression('#<meta property="og:image" content="https?://[^"]+/images/og-image\.png\?v=1">#', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="'.config('game.colors.background').'">', $html);
        $this->assertStringNotContainsString('noindex', $html);
    }

    public function test_shared_links_open_the_shared_board(): void
    {
        $this->get('/?mode=daily&grid=6x6')
            ->assertSee('data-initial-mode="daily"', false)
            ->assertSee('data-initial-grid="6x6"', false)
            ->assertSee("Today's 6 × 6 daily challenge");

        $this->get('/?grid=bogus&mode=nope')
            ->assertOk()
            ->assertSee('data-initial-grid=""', false)
            ->assertSee('data-initial-mode=""', false);
    }

    public function test_account_pages_stay_out_of_search_results(): void
    {
        $this->get('/login')->assertSee('<meta name="robots" content="noindex">', false);
    }

    public function test_the_icons_and_preview_image_exist(): void
    {
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('images/og-image.png')), 0, 2));
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('images/apple-touch-icon.png')), 0, 2));
    }
}
