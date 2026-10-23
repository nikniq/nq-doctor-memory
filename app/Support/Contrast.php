<?php

namespace App\Support;

/**
 * WCAG 2.1 contrast ratio between two colours.
 */
class Contrast
{
    /**
     * Contrast ratio from 1 (identical) to 21 (black on white).
     */
    public static function ratio(string $hexA, string $hexB): float
    {
        $lighter = max(self::luminance($hexA), self::luminance($hexB));
        $darker = min(self::luminance($hexA), self::luminance($hexB));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Relative luminance of a #rgb or #rrggbb colour.
     */
    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        [$red, $green, $blue] = array_map(function (string $pair): float {
            $channel = hexdec($pair) / 255;

            return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
