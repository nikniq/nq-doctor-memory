<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * A 4x4 solo free-play game that started a minute ago and is still open.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'difficulty' => '4x4',
            'played_on' => null,
            'players' => 1,
            'pairs' => 8,
            'started_at' => now()->subMinute(),
        ];
    }

    public function grid(string $grid, int $pairs): static
    {
        return $this->state(fn () => ['difficulty' => $grid, 'pairs' => $pairs]);
    }

    public function daily(): static
    {
        return $this->state(fn () => ['played_on' => now()->toDateString()]);
    }

    public function twoPlayer(): static
    {
        return $this->state(fn () => ['players' => 2]);
    }

    public function finished(int $turns = 12, int $seconds = 45): static
    {
        return $this->state(fn () => [
            'turns' => $turns,
            'seconds' => $seconds,
            'finished_at' => now(),
        ]);
    }
}
