<?php

namespace Tests\Feature;

use App\Support\Contrast;
use Tests\TestCase;

/**
 * The configured theme (config/game.php, overridable from .env) must stay
 * readable: WCAG AA is 4.5:1 for text and 3:1 for large UI shapes.
 */
class ThemeContrastTest extends TestCase
{
    public function test_the_contrast_formula_matches_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Contrast::ratio('#ffffff', '#000000'), 0.01);
        $this->assertEqualsWithDelta(1.0, Contrast::ratio('#abc', '#aabbcc'), 0.001);
    }

    public function test_the_configured_colours_meet_aa(): void
    {
        $colors = config('game.colors');

        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($colors['button_text'], $colors['button']), 'button text on buttons');
        $this->assertGreaterThanOrEqual(4.5, Contrast::ratio($colors['text'], $colors['card_front']), 'text on cards and panels');
        $this->assertGreaterThanOrEqual(3.0, Contrast::ratio($colors['button'], $colors['background']), 'buttons against the page');
    }

    public function test_the_page_exposes_the_button_text_colour(): void
    {
        $this->get('/')->assertSee('--game-button-text', false);
    }
}
