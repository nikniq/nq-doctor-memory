<?php

namespace App\Support;

/**
 * How likely a result is for a player with perfect memory, the best a fair
 * player can do. Used to hold results that are too good to be true.
 */
class TurnOdds
{
    /**
     * Probability that a perfect-memory solo player clears `pairs` pairs in
     * at most `turns` turns.
     *
     * State: a = untouched pairs, b = pairs with one card seen, c = pairs
     * with both cards seen but not yet taken (0 or 1). Each turn the player
     * takes a known pair if there is one; otherwise flips an unseen card,
     * then its known twin if it has one, otherwise another unseen card.
     */
    public static function atMost(int $pairs, int $turns): float
    {
        /** @var array<string, float> $states */
        $states = ["{$pairs},0,0" => 1.0];
        $done = 0.0;

        for ($turn = 1; $turn <= $turns && $states !== []; $turn++) {
            $next = [];
            $emit = function (int $a, int $b, int $c, float $p) use (&$next, &$done): void {
                if ($a === 0 && $b === 0 && $c === 0) {
                    $done += $p;

                    return;
                }
                $key = "{$a},{$b},{$c}";
                $next[$key] = ($next[$key] ?? 0.0) + $p;
            };

            foreach ($states as $key => $p) {
                [$a, $b, $c] = array_map('intval', explode(',', $key));

                if ($c > 0) {
                    $emit($a, $b, $c - 1, $p);

                    continue;
                }

                $unseen = 2 * $a + $b;

                // The first card is the twin of one already seen: a match.
                if ($b > 0) {
                    $emit($a, $b - 1, 0, $p * $b / $unseen);
                }

                if ($a > 0) {
                    $firstIsNew = $p * (2 * $a) / $unseen;
                    $rest = $unseen - 1;
                    // Second card: its twin, a seen card's twin, or another new card.
                    $emit($a - 1, $b, 0, $firstIsNew / $rest);
                    if ($b > 0) {
                        $emit($a - 1, $b, 1, $firstIsNew * $b / $rest);
                    }
                    if ($a > 1) {
                        $emit($a - 2, $b + 2, 0, $firstIsNew * (2 * $a - 2) / $rest);
                    }
                }
            }

            $states = $next;
        }

        return $done;
    }
}
