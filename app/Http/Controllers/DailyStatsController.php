<?php

namespace App\Http\Controllers;

use App\Support\Daily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * How everyone did on today's board: player count, median and a histogram.
 */
class DailyStatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $grids = config('game.difficulties');
        $data = $request->validate([
            'difficulty' => ['required', Rule::in(array_keys($grids))],
        ]);

        $grid = $data['difficulty'];
        $date = now()->toDateString();
        $pairs = $grids[$grid]['pairs'];

        $stats = Cache::remember("daily-stats:{$date}:{$grid}", 60, function () use ($date, $grid, $pairs) {
            $turns = Daily::rankedGames($date, $grid)->orderBy('turns')->pluck('turns')->map(fn ($t) => (int) $t)->all();
            $players = count($turns);

            return [
                'date' => $date,
                'number' => Daily::number($date),
                'players' => $players,
                'median_turns' => $players ? $this->median($turns) : null,
                'buckets' => $players ? $this->buckets($turns, $pairs * 3) : [],
            ];
        });

        return response()->json($stats);
    }

    /**
     * @param  list<int>  $sorted
     */
    private function median(array $sorted): float
    {
        $middle = intdiv(count($sorted), 2);

        return count($sorted) % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * One bucket per turn count from the lowest result up to $cap, then one
     * "cap+" bucket for everything slower.
     *
     * @param  list<int>  $sorted
     * @return list<array{turns: int|string, count: int}>
     */
    private function buckets(array $sorted, int $cap): array
    {
        $counts = array_count_values($sorted);
        $low = min($sorted[0], $cap);
        $high = min(max($sorted), $cap);

        $buckets = [];
        for ($turns = $low; $turns <= $high; $turns++) {
            $buckets[] = ['turns' => $turns, 'count' => $counts[$turns] ?? 0];
        }

        $over = count(array_filter($sorted, fn (int $t) => $t > $cap));
        if ($over > 0) {
            $buckets[] = ['turns' => ($cap + 1).'+', 'count' => $over];
        }

        return $buckets;
    }
}
