<?php

namespace App\Support;

/**
 * Game durations as minutes:seconds, matching formatTime() in the game script.
 */
class Duration
{
    /**
     * 0:05, 1:30, 61:40 — minutes keep counting past the hour.
     */
    public static function format(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
