<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_in_rankings')->default(true)->after('avatar_url');
            $table->boolean('show_avatar')->default(false)->after('show_in_rankings');
            $table->timestamp('name_changed_at')->nullable()->after('show_avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['show_in_rankings', 'show_avatar', 'name_changed_at']);
        });
    }
};
