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
        'unranked_reason',
        'attempt',
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
            'attempt' => 'integer',
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

    public const PRACTICE = 'practice';

    public const IMPLAUSIBLE = 'implausible';

    /**
     * What the player is told about a game that is not ranked.
     *
     * @var array<string, string>
     */
    public const UNRANKED_MESSAGES = [
        self::PRACTICE => "Replays of today's board are practice and don't go on the top scores.",
        self::IMPLAUSIBLE => 'This result is being held for review.',
    ];

    public function isRanked(): bool
    {
        return $this->unranked_reason === null;
    }

    public function unrankedMessage(): ?string
    {
        return self::UNRANKED_MESSAGES[$this->unranked_reason] ?? null;
    }

    /**
     * Games that count towards top scores and rankings.
     *
     * @param  Builder<Game>  $query
     * @return Builder<Game>
     */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->whereNull('unranked_reason');
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
