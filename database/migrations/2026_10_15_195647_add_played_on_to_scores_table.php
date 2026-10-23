<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            // Set for daily-challenge games (the date of the shared board); null for free play.
            $table->date('played_on')->nullable()->after('difficulty');
            $table->index(['difficulty', 'played_on', 'moves', 'seconds']);
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropIndex(['difficulty', 'played_on', 'moves', 'seconds']);
            $table->dropColumn('played_on');
        });
    }
};
