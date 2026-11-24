<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('penugasans') && !Schema::hasColumn('penugasans', 'catatan_penilaian')) {
            Schema::table('penugasans', function (Blueprint $table) {
                $table->text('catatan_penilaian')->nullable()->after('skor_rekap_json');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('penugasans') && Schema::hasColumn('penugasans', 'catatan_penilaian')) {
            Schema::table('penugasans', function (Blueprint $table) {
                $table->dropColumn('catatan_penilaian');
            });
        }
    }
};
