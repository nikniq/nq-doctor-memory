<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per game the server saw start. A score must reference an
        // open game, which lets the server sanity-check the reported time.
        Schema::create('games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('difficulty', 16);
            $table->date('played_on')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('score_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
