<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // null = ranked; otherwise why not: 'practice' or 'implausible'.
            $table->string('unranked_reason', 16)->nullable()->after('finished_at');
            // Solo daily games: 1 for the player's first game on that board.
            $table->unsignedTinyInteger('attempt')->nullable()->after('unranked_reason');
            $table->index(['user_id', 'played_on', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'played_on', 'difficulty']);
            $table->dropColumn(['unranked_reason', 'attempt']);
        });
    }
};
