<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The named difficulties became grid sizes (columns x rows) with the
     * same pair counts, so existing scores stay on the matching board.
     *
     * @var array<string, string>
     */
    private array $map = ['easy' => '4x3', 'medium' => '4x4', 'hard' => '6x4'];

    public function up(): void
    {
        foreach ($this->map as $old => $new) {
            DB::table('scores')->where('difficulty', $old)->update(['difficulty' => $new]);
            DB::table('games')->where('difficulty', $old)->update(['difficulty' => $new]);
        }
    }

    public function down(): void
    {
        foreach ($this->map as $old => $new) {
            DB::table('scores')->where('difficulty', $new)->update(['difficulty' => $old]);
            DB::table('games')->where('difficulty', $new)->update(['difficulty' => $old]);
        }
    }
};
