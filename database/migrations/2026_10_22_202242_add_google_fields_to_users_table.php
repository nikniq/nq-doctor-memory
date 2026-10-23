<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('avatar_url', 2048)->nullable()->after('google_id');
            // Accounts created through Google have no password until one is set.
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Google-only accounts have no password. Give them an unusable one so
        // the column can become NOT NULL; they can set a real one by reset.
        DB::table('users')->whereNull('password')->update(['password' => Hash::make(Str::random(40))]);

        // MySQL DDL is not transactional: make the change that can fail first,
        // so a failure never leaves the Google columns already dropped.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'avatar_url']);
        });
    }
};
