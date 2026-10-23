<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Games started before the pair count was stored have pairs = 0, which
     * would let one of them be finished in a single turn.
     */
    public function up(): void
    {
        foreach (config('game.difficulties') as $grid => $settings) {
            DB::table('games')->where('pairs', 0)->where('difficulty', $grid)->update(['pairs' => $settings['pairs']]);
        }
    }

    /**
     * The original zeroes carried no information, so there is nothing to restore.
     */
    public function down(): void
    {
        //
    }
};
