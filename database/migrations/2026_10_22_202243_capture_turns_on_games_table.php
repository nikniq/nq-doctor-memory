<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // completed_at meant "a score was submitted"; name it for that.
            $table->renameColumn('completed_at', 'scored_at');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('players')->default(1)->after('played_on');
            $table->unsignedSmallInteger('pairs')->default(0)->after('players');
            $table->unsignedInteger('turns')->nullable()->after('started_at');
            $table->unsignedInteger('seconds')->nullable()->after('turns');
            $table->timestamp('finished_at')->nullable()->after('seconds');

            $table->index(['user_id', 'finished_at']);
            $table->index(['difficulty', 'players', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // MySQL reuses the composite index for the foreign key, so the key
            // must go before the index or the drop fails with error 1553.
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'finished_at']);
            $table->dropIndex(['difficulty', 'players', 'finished_at']);
            $table->dropColumn(['user_id', 'players', 'pairs', 'turns', 'seconds', 'finished_at']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->renameColumn('scored_at', 'completed_at');
        });
    }
};
