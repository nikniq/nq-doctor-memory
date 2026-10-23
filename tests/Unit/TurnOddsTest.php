<?php

namespace Tests\Unit;

use App\Support\TurnOdds;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class TurnOddsTest extends TestCase
{
    /**
     * A perfect game needs every first flip to be followed by its twin:
     * probability 1 / (2n - 1)!!.
     */
    #[TestWith([2, 3])]
    #[TestWith([3, 15])]
    #[TestWith([6, 10395])]
    #[TestWith([8, 2027025])]
    public function test_a_perfect_game_has_double_factorial_odds(int $pairs, int $oneIn): void
    {
        $this->assertEqualsWithDelta(1 / $oneIn, TurnOdds::atMost($pairs, $pairs), 1e-12);
    }

    public function test_enough_turns_make_finishing_certain(): void
    {
        $this->assertEqualsWithDelta(1.0, TurnOdds::atMost(18, 200), 1e-9);
        $this->assertSame(0.0, TurnOdds::atMost(8, 7));
    }

    public function test_odds_rise_with_more_turns(): void
    {
        $this->assertLessThan(TurnOdds::atMost(8, 10), TurnOdds::atMost(8, 9));
    }
}
