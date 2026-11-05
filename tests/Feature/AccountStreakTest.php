<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStreakTest extends TestCase
{
    use RefreshDatabase;

    private function streak(): mixed
    {
        preg_match("/data-account-streak='([^']*)'/", $this->get('/')->getContent(), $match);

        return json_decode(html_entity_decode($match[1]), true);
    }

    private function dailyOn(User $user, string $day, array $state = []): void
    {
        Game::factory()->for($user)->finished()->create(['played_on' => $day] + $state);
    }

    public function test_the_streak_counts_consecutive_days_up_to_the_latest(): void
    {
        $this->travelTo('2026-11-06 12:00:00');
        $user = User::factory()->create();
        foreach (['2026-11-06', '2026-11-05', '2026-11-05', '2026-11-04', '2026-11-01'] as $day) {
            $this->dailyOn($user, $day);
        }
        $this->dailyOn($user, '2026-11-03', ['players' => 2]); // two-player games do not count

        $this->actingAs($user);

        $this->assertSame(['last' => '2026-11-06', 'count' => 3], $this->streak());
    }

    public function test_players_without_daily_games_or_accounts_have_none(): void
    {
        $this->assertNull($this->streak());

        $user = User::factory()->create();
        Game::factory()->for($user)->finished()->create(); // free play
        $this->actingAs($user);
        $this->assertNull($this->streak());
    }
}
