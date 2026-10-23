<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Game extends Model
{
    use HasFactory;
    use HasUuids;
    use Prunable;

    protected $fillable = [
        'user_id',
        'difficulty',
        'played_on',
        'players',
        'pairs',
        'started_at',
        'turns',
        'seconds',
        'finished_at',
        'scored_at',
        'score_id',
    ];

    protected function casts(): array
    {
        return [
            'played_on' => 'date:Y-m-d',
            'players' => 'integer',
            'pairs' => 'integer',
            'turns' => 'integer',
            'seconds' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'scored_at' => 'datetime',
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
     * @return BelongsTo<Score, $this>
     */
    public function score(): BelongsTo
    {
        return $this->belongsTo(Score::class);
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    public function isScored(): bool
    {
        return $this->scored_at !== null;
    }

    public function isDaily(): bool
    {
        return $this->played_on !== null;
    }

    /**
     * Seconds between the server seeing the game start and now.
     */
    public function serverElapsedSeconds(): int
    {
        return (int) $this->started_at->diffInSeconds(now(), absolute: true);
    }

    /**
     * Finished games are kept as each player's history.
     *
     * @param  Builder<Game>  $query
     * @return Builder<Game>
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereNotNull('finished_at');
    }

    /**
     * Games that were started but never finished are dropped after a day.
     *
     * @return Builder<Game>
     */
    public function prunable(): Builder
    {
        return static::whereNull('finished_at')->where('created_at', '<', now()->subDay());
    }
}
