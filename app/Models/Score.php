<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Score extends Model
{
    protected $fillable = ['user_id', 'player_name', 'difficulty', 'played_on', 'moves', 'seconds'];

    protected function casts(): array
    {
        return [
            'moves' => 'integer',
            'seconds' => 'integer',
            'played_on' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Best scores first: fewest moves, then fastest, then earliest.
     */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->orderBy('moves')->orderBy('seconds')->orderBy('id');
    }

    /**
     * Scores that compete on the same board: a difficulty in free play, or
     * a difficulty on a given daily-challenge date.
     */
    public function scopeForBoard(Builder $query, string $difficulty, ?string $playedOn): Builder
    {
        return $query->where('difficulty', $difficulty)
            ->when(
                $playedOn,
                fn (Builder $q) => $q->whereDate('played_on', $playedOn),
                fn (Builder $q) => $q->whereNull('played_on'),
            );
    }

    /**
     * Scores strictly better than this one on the same board.
     */
    public function scopeBetterThan(Builder $query, Score $score): Builder
    {
        return $query->where(function (Builder $q) use ($score) {
            $q->where('moves', '<', $score->moves)
                ->orWhere(fn ($w) => $w->where('moves', $score->moves)->where('seconds', '<', $score->seconds))
                ->orWhere(fn ($w) => $w->where('moves', $score->moves)->where('seconds', $score->seconds)->where('id', '<', $score->id));
        });
    }
}
