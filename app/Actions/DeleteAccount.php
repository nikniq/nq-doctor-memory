<?php

namespace App\Actions;

use App\Models\Game;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Remove an account and everything recorded about its player.
 */
class DeleteAccount
{
    /**
     * Every table with a user_id column. A test fails if a table gains one
     * without being listed here, so new personal data is not left behind.
     *
     * @var list<string>
     */
    public const TABLES_WITH_USER_ID = ['games', 'scores', 'sessions'];

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            Score::where('user_id', $user->id)->delete();
            Game::where('user_id', $user->id)->delete();
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }
}
