<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->string('player_name', 24);
            $table->string('difficulty', 16);
            $table->unsignedInteger('moves');
            $table->unsignedInteger('seconds');
            $table->timestamps();

            $table->index(['difficulty', 'moves', 'seconds']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
